<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

class PaymentController extends Controller
{
    /**
     * ✅ Helper: find student by registration OR by email/phone (old+new flow)
     */
    private function findStudentByRegistrationOrContact(int $registrationId, ?string $email, ?string $phone)
    {
        // 1) Old flow: students.registration_id = registration.id
        $student = DB::table('students')
            ->where('registration_id', $registrationId)
            ->select('id', 'student_code', 'user_id', 'registration_id', 'department_id')
            ->first();

        if ($student) {
            return $student;
        }

        // 2) New flow: match by email/phone
        return DB::table('students')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('registrations', 'students.registration_id', '=', 'registrations.id')
            ->where(function ($q) use ($email, $phone) {
                if (!empty($email)) {
                    $q->where('users.email', $email)
                        ->orWhere('registrations.personal_email', $email);
                }
                if (!empty($phone)) {
                    $q->orWhere('registrations.phone_number', $phone);
                }
            })
            ->select('students.id', 'students.student_code', 'students.user_id', 'students.registration_id', 'students.department_id')
            ->orderByDesc('students.id')
            ->first();
    }

    /**
     * ✅ Helper: upsert academic period without overwriting created_at
     */
    private function upsertAcademicPeriodNoCreatedAtOverwrite(int $studentId, string $academicYear, int $semester, array $data): void
    {
        $updated = DB::table('student_academic_periods')
            ->where('student_id', $studentId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->update(array_merge($data, ['updated_at' => now()]));

        if ($updated === 0) {
            DB::table('student_academic_periods')->insert(array_merge([
                'student_id' => $studentId,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'created_at' => now(),
                'updated_at' => now(),
            ], $data));
        }
    }

    /**
     * ✅ Pick class group for student major/year/semester/shift with capacity
     * ✅ Fast + safe: choose first class_group with available seats
     */
    private function pickAvailableClassGroup(int $majorId, string $academicYear, int $semester, ?string $shift = null)
    {
        $query = DB::table('class_groups')
            ->where('major_id', $majorId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester);

        if (!empty($shift) && Schema::hasColumn('class_groups', 'shift')) {
            $query->where('shift', $shift);
        }

        $groups = $query->orderBy('id')->get();

        foreach ($groups as $g) {
            $count = DB::table('class_group_students')
                ->where('class_group_id', $g->id)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->count();

            $capacity = (int) ($g->capacity ?? 0);

            // if capacity is 0, treat as unlimited (optional behavior)
            if ($capacity <= 0) {
                return $g;
            }

            if ($count < $capacity) {
                return $g;
            }
        }

        return null;
    }

    /**
     * ✅ Ensure student is assigned to class group AND enrolled to all courses of that class group
     * ✅ Idempotent: safe to call many times (no duplicates)
     */
    private function ensureAutoEnrollmentAfterPaid(int $studentId, int $majorId, string $academicYear, int $semester, ?string $shift = null): void
    {
        // 1) Already assigned?
        $existing = DB::table('class_group_students')
            ->where('student_id', $studentId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->first();

        if (!$existing) {
            $group = $this->pickAvailableClassGroup($majorId, $academicYear, $semester, $shift);

            if (!$group) {
                // No class group available: do NOT throw error (payment already done)
                // Admin can create class group later and enroll manually.
                Log::warning('No class group available for auto-enrollment', [
                    'student_id' => $studentId,
                    'major_id' => $majorId,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                    'shift' => $shift,
                ]);
                return;
            }

            // Insert assignment (unique prevents duplicates)
            DB::table('class_group_students')->updateOrInsert(
                [
                    'student_id' => $studentId,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                ],
                [
                    'class_group_id' => $group->id,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $existing = DB::table('class_group_students')
                ->where('student_id', $studentId)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->first();
        }

        if (!$existing) {
            return;
        }

        $classGroupId = (int) $existing->class_group_id;

        // 2) Enroll to all courses in that class group (course offerings)
        $courses = DB::table('courses')
            ->where('class_group_id', $classGroupId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->select('id')
            ->get();

        foreach ($courses as $c) {
            DB::table('course_enrollments')->updateOrInsert(
                [
                    'student_id' => $studentId,
                    'course_id'  => $c->id,
                ],
                [
                    'status'      => 'ENROLLED',
                    'progress'    => 0,
                    'enrolled_at' => now(),
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }
    }

    /**
     * ✅ Generate ABA QR
     * ✅ semester comes from frontend (optional)
     * ✅ create payment_transactions + update student_academic_periods.tran_id
     */
    public function generateQr(Request $request)
    {
        Log::info('🔥 generateQr hit');

        $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'semester' => 'nullable|integer|in:1,2',
        ]);

        $semester = (int) ($request->input('semester', 1));

        DB::beginTransaction();

        try {
            $registration = DB::table('registrations')
                ->join('majors', 'registrations.major_id', '=', 'majors.id')
                ->where('registrations.id', $request->registration_id)
                ->select('registrations.*', 'majors.registration_fee')
                ->first();

            if (!$registration) {
                DB::rollBack();
                return response()->json(['error' => 'Invalid registration'], 400);
            }

            $studentLink = $this->findStudentByRegistrationOrContact(
                (int) $registration->id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if (!$studentLink) {
                DB::rollBack();
                return response()->json(['error' => 'Student not found for this registration'], 404);
            }

            // ensure academic period row exists
            $period = DB::table('student_academic_periods')
                ->where('student_id', $studentLink->id)
                ->where('academic_year', $registration->academic_year)
                ->where('semester', $semester)
                ->first();

            if (!$period) {
                $this->upsertAcademicPeriodNoCreatedAtOverwrite(
                    (int) $studentLink->id,
                    (string) $registration->academic_year,
                    (int) $semester,
                    [
                        'status' => 'ACTIVE',
                        'tuition_amount' => (float) $registration->registration_fee,
                        'payment_status' => 'PENDING',
                        'paid_at' => null,
                    ]
                );

                $period = DB::table('student_academic_periods')
                    ->where('student_id', $studentLink->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $semester)
                    ->first();
            }

            // block if already PAID
            if ($period && strtoupper((string) $period->payment_status) === 'PAID') {
                DB::rollBack();
                return response()->json(['error' => 'Already paid for this academic year and semester.'], 409);
            }

            /* ================= REQUIRED FIELDS ================= */

            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');
            $tranId     = 'REG-' . $registration->id . '-S' . $semester . '-' . time();

            $amount = number_format((float) $registration->registration_fee, 2, '.', '');

            DB::table('payment_transactions')->updateOrInsert(
                ['tran_id' => $tranId],
                [
                    'amount' => $amount,
                    'status' => 'PENDING',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // update academic period with tran_id
            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                DB::table('student_academic_periods')
                    ->where('student_id', $studentLink->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $semester)
                    ->update([
                        'tran_id' => $tranId,
                        'payment_status' => 'PENDING',
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('student_academic_periods')
                    ->where('student_id', $studentLink->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $semester)
                    ->update([
                        'payment_status' => 'PENDING',
                        'updated_at' => now(),
                    ]);
            }

            $currency = 'USD';

            /* ================= OPTIONAL PAYER INFO ================= */

            $firstName = trim($registration->first_name ?? '');
            $lastName  = trim($registration->last_name ?? '');
            $email     = trim($registration->personal_email ?? '');
            $phone     = preg_replace('/\D/', '', $registration->phone_number ?? '');

            /* ================= BASE64 FIELDS ================= */

            $items = base64_encode(json_encode([
                [
                    'name'     => 'Registration Fee',
                    'quantity' => 1,
                    'price'    => $amount
                ]
            ], JSON_UNESCAPED_SLASHES));

            $callbackUrl     = base64_encode(config('payway.callback'));
            $returnDeeplink  = '';
            $customFields    = '';
            $returnParams    = '';
            $payout          = '';
            $lifetime        = 6;
            $qrImageTemplate = 'template3_color';
            $purchaseType    = 'purchase';
            $paymentOption   = 'abapay_khqr';

            /* ================= HASH STRING (EXACT ORDER) ================= */

            $hashString =
                $reqTime .
                $merchantId .
                $tranId .
                $amount .
                $items .
                $firstName .
                $lastName .
                $email .
                $phone .
                $purchaseType .
                $paymentOption .
                $callbackUrl .
                $returnDeeplink .
                $currency .
                $customFields .
                $returnParams .
                $payout .
                $lifetime .
                $qrImageTemplate;

            $hash = base64_encode(
                hash_hmac(
                    'sha512',
                    $hashString,
                    config('payway.api_key'),
                    true
                )
            );

            /* ================= PAYLOAD ================= */

            $payload = [
                'req_time'          => $reqTime,
                'merchant_id'       => $merchantId,
                'tran_id'           => $tranId,
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $email,
                'phone'             => $phone,
                'amount'            => $amount,
                'purchase_type'     => $purchaseType,
                'payment_option'    => $paymentOption,
                'items'             => $items,
                'currency'          => $currency,
                'callback_url'      => $callbackUrl,
                'return_deeplink'   => '',
                'custom_fields'     => '',
                'return_params'     => '',
                'payout'            => '',
                'lifetime'          => $lifetime,
                'qr_image_template' => $qrImageTemplate,
                'hash'              => $hash,
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post(
                'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/generate-qr',
                $payload
            );

            if (!$response->successful()) {
                DB::rollBack();
                Log::error('ABA QR Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                    'hash_string_length' => strlen($hashString)
                ]);
                return response()->json($response->json(), 403);
            }

            DB::commit();

            return response()->json([
                'tran_id' => $tranId,
                'qr'      => $response->json(),
                'meta'    => [
                    'registration_id' => (int) $registration->id,
                    'student_id'      => (int) $studentLink->id,
                    'academic_year'   => (string) $registration->academic_year,
                    'semester'        => (int) $semester,
                    'amount'          => $amount,
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('generateQr error', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['error' => 'failed'], 500);
        }
    }

    public function checkPaymentStatus($tranId)
    {
        try {
            $tx = DB::table('payment_transactions')->where('tran_id', $tranId)->first();

            if (!$tx) {
                return response()->json([
                    'tran_id' => $tranId,
                    'status' => ['code' => '1', 'message' => 'PENDING', 'lang' => 'en']
                ]);
            }

            $paid = in_array(strtoupper((string) $tx->status), ['PAID', 'SUCCESS', 'COMPLETED', 'DONE'], true);

            return response()->json([
                'tran_id' => $tranId,
                'status' => [
                    'code' => $paid ? '0' : '1',
                    'message' => strtoupper((string) $tx->status),
                    'lang' => 'en'
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('checkPaymentStatus error', [
                'tran_id' => $tranId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * ✅ ABA Callback
     * ✅ Must return 200 {ack: ok}
     * ✅ Update payment_transactions + student_academic_periods + auto enroll on PAID
     */
    public function paymentCallback(Request $request)
    {
        Log::info('ABA CALLBACK RECEIVED', $request->all());

        if (!$request->has('tran_id')) {
            return response()->json(['error' => 'Missing tran_id'], 400);
        }

        $tranId = (string) $request->tran_id;

        $status = ((string) $request->payment_status_code === '0') ? 'PAID' : 'FAILED';

        DB::table('payment_transactions')->updateOrInsert(
            ['tran_id' => $tranId],
            [
                'status' => $status,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        try {
            DB::beginTransaction();

            // 1) Find period by tran_id (best)
            $period = null;
            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                $period = DB::table('student_academic_periods')->where('tran_id', $tranId)->first();
            }

            if ($period) {
                $update = [
                    'payment_status' => $status,
                    'updated_at' => now(),
                ];
                if ($status === 'PAID') {
                    $update['paid_at'] = now();
                }

                DB::table('student_academic_periods')->where('id', $period->id)->update($update);

                // upgrade role
                if ($status === 'PAID') {
                    $student = DB::table('students')->where('id', $period->student_id)->first();
                    if ($student) {
                        User::where('id', $student->user_id)
                            ->where('role', 'register')
                            ->update(['role' => 'student']);
                    }

                    // Need major/year/semester for auto-enroll
                    // We'll get latest registration for this student in that academic year if possible
                    $reg = DB::table('registrations')
                        ->leftJoin('users', 'users.email', '=', 'registrations.personal_email')
                        ->leftJoin('students', function ($join) {
                            $join->on('students.registration_id', '=', 'registrations.id')
                                ->orOn('students.user_id', '=', 'users.id');
                        })
                        ->where('students.id', $period->student_id)
                        ->where('registrations.academic_year', $period->academic_year)
                        ->orderByDesc('registrations.id')
                        ->select('registrations.major_id', 'registrations.shift', 'registrations.academic_year')
                        ->first();

                    if ($reg) {
                        $this->ensureAutoEnrollmentAfterPaid(
                            (int) $period->student_id,
                            (int) $reg->major_id,
                            (string) $period->academic_year,
                            (int) $period->semester,
                            $reg->shift ?? null
                        );
                    } else {
                        // fallback: if no reg found, do nothing (admin can enroll manually)
                        Log::warning('Auto-enroll skipped: registration not found for student/year', [
                            'student_id' => $period->student_id,
                            'academic_year' => $period->academic_year,
                            'semester' => $period->semester,
                        ]);
                    }
                }

                DB::commit();
                return response()->json(['ack' => 'ok']);
            }

            // If no period found, still return ok
            DB::commit();
            return response()->json(['ack' => 'ok']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('paymentCallback error', [
                'tran_id' => $tranId,
                'error' => $e->getMessage()
            ]);

            // ABA must still receive 200 OK
            return response()->json(['ack' => 'ok']);
        }
    }
}
