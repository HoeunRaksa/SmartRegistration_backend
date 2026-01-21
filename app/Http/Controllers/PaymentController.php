<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class PaymentController extends Controller
{
    /**
     * ✅ Helper: find student by registration OR by email/phone (works for old+new flow)
     * (Same logic as your RegistrationController)
     */
    private function findStudentByRegistrationOrContact(int $registrationId, ?string $email, ?string $phone)
    {
        // 1) Direct link (old flow)
        $student = DB::table('students')
            ->where('registration_id', $registrationId)
            ->select('id', 'student_code', 'user_id', 'registration_id')
            ->first();

        if ($student) {
            return $student;
        }

        // 2) Match by email/phone (new flow safe)
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
            ->select(
                'students.id',
                'students.student_code',
                'students.user_id',
                'students.registration_id'
            )
            ->orderByDesc('students.id')
            ->first();
    }

    /**
     * ✅ Helper: upsert academic period WITHOUT overwriting created_at.
     * (Same logic as your RegistrationController)
     */
    private function upsertAcademicPeriodNoCreatedAtOverwrite(int $studentId, string $academicYear, int $semester, array $data)
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
     * ✅ Generate ABA KHQR
     * ✅ New flow: payment status lives in student_academic_periods (NOT registrations)
     * ✅ IMPORTANT: DO NOT TOUCH ABA payload / hash order / fields
     */
    public function generateQr(Request $request)
    {
        Log::info('🔥 generateQr hit');

        $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            // ✅ allow frontend to send semester (optional)
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

            // ✅ Find related student (works for returning students too)
            $studentLink = $this->findStudentByRegistrationOrContact(
                (int) $registration->id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if (!$studentLink) {
                // If your flow guarantees student exists before payment, treat as error
                DB::rollBack();
                return response()->json(['error' => 'Student not found for this registration'], 404);
            }

            // ✅ Ensure academic period exists (idempotent)
            $period = DB::table('student_academic_periods')
                ->where('student_id', $studentLink->id)
                ->where('academic_year', $registration->academic_year)
                ->where('semester', $semester)
                ->first();

            if (!$period) {
                // create period row if missing
                $this->upsertAcademicPeriodNoCreatedAtOverwrite(
                    (int) $studentLink->id,
                    (string) $registration->academic_year,
                    (int) $semester,
                    [
                        'status' => 'ACTIVE',
                        'tuition_amount' => $registration->registration_fee,
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

            // ✅ Block if already PAID in this academic period
            if ($period && strtoupper((string) $period->payment_status) === 'PAID') {
                DB::rollBack();
                return response()->json([
                    'error' => 'Already paid for this academic year and semester.'
                ], 409);
            }

            /* ================= REQUIRED FIELDS ================= */

            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');
            $tranId     = 'REG-' . $registration->id . '-S' . $semester . '-' . time();

            $amount = number_format($registration->registration_fee, 2, '.', '');

            DB::table('payment_transactions')->insert([
                'tran_id' => $tranId,
                'amount'  => $amount,
                'status'  => 'PENDING',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // ✅ New flow: DO NOT update registrations payment columns
            // ✅ Update student_academic_periods to link tran_id
            // NOTE: Only do this if your table has tran_id column.
            // If you don't have it, remove these 2 lines and keep payment_transactions only.
            if (DB::getSchemaBuilder()->hasColumn('student_academic_periods', 'tran_id')) {
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
                // Still ensure payment_status stays PENDING (no tran_id column)
                DB::table('student_academic_periods')
                    ->where('student_id', $studentLink->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $semester)
                    ->update([
                        'payment_status' => 'PENDING',
                        'updated_at' => now(),
                    ]);
            }

            $currency   = 'USD';

            /* ================= OPTIONAL PAYER INFO ================= */

            $firstName = trim($registration->first_name ?? '');
            $lastName  = trim($registration->last_name ?? '');
            $email     = trim($registration->personal_email ?? '');

            // 🔴 MUST be digits only
            $phone = preg_replace('/\D/', '', $registration->phone_number ?? '');

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
                    config('payway.api_key'), // ✅ HMAC API KEY
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
                'amount'            => $amount, // 🔴 STRING, NOT FLOAT
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

            /* ================= CALL ABA QR API ================= */

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
            Log::error($e->getMessage());
            return response()->json(['error' => 'failed'], 500);
        }
    }

    public function checkPaymentStatus($tranId)
    {
        try {
            $tx = DB::table('payment_transactions')
                ->where('tran_id', $tranId)
                ->first();

            if (!$tx) {
                return response()->json([
                    'tran_id' => $tranId,
                    'status' => [
                        'code' => '1',
                        'message' => 'PENDING',
                        'lang' => 'en'
                    ]
                ]);
            }

            return response()->json([
                'tran_id' => $tranId,
                'status' => [
                    'code' => in_array($tx->status, ['PAID', 'SUCCESS', 'COMPLETED']) ? '0' : '1',
                    'message' => $tx->status,
                    'lang' => 'en'
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('checkPaymentStatus error', [
                'tran_id' => $tranId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'Internal error'
            ], 500);
        }
    }

    /**
     * ✅ ABA Callback
     * ✅ IMPORTANT: Always return 200 with {ack: ok}
     * ✅ New flow: Update student_academic_periods by tran_id if exists
     * ✅ If you don't have tran_id column in student_academic_periods, it will fallback to best-effort matching.
     */
    public function paymentCallback(Request $request)
    {
        Log::info('ABA CALLBACK RECEIVED', $request->all());

        if (!$request->has('tran_id')) {
            return response()->json(['error' => 'Missing tran_id'], 400);
        }

        $tranId = $request->tran_id;

        // ABA: payment_status_code == 0 means success
        $status = ((string) $request->payment_status_code === '0') ? 'PAID' : 'FAILED';

        // ✅ Update transaction (safe even if already updated)
        DB::table('payment_transactions')->updateOrInsert(
            ['tran_id' => $tranId],
            [
                'status' => $status,
                'updated_at' => now(),
            ]
        );

        try {
            DB::beginTransaction();

            // ✅ Preferred: find academic period by tran_id
            $period = null;

            if (DB::getSchemaBuilder()->hasColumn('student_academic_periods', 'tran_id')) {
                $period = DB::table('student_academic_periods')
                    ->where('tran_id', $tranId)
                    ->first();
            }

            if ($period) {
                // ✅ Update academic period
                $update = [
                    'payment_status' => $status,
                    'updated_at' => now(),
                ];
                if ($status === 'PAID') {
                    $update['paid_at'] = now();
                }

                DB::table('student_academic_periods')
                    ->where('id', $period->id)
                    ->update($update);

                // ✅ Upgrade user role (when paid)
                if ($status === 'PAID') {
                    $student = DB::table('students')->where('id', $period->student_id)->first();
                    if ($student) {
                        $user = DB::table('users')->where('id', $student->user_id)->first();
                        if ($user) {
                            User::where('id', $user->id)
                                ->where('role', 'register')
                                ->update(['role' => 'student']);
                        }
                    }
                }

                DB::commit();
                return response()->json(['ack' => 'ok']); // ✅ ABA MUST RECEIVE 200
            }

            /**
             * ✅ Fallback mode (if you don't have tran_id column in periods)
             * Try to find registration by old registration payment_tran_id ONLY if column exists.
             * This keeps backward compatibility without breaking your new flow.
             */
            $registration = null;

            if (DB::getSchemaBuilder()->hasColumn('registrations', 'payment_tran_id')) {
                $registration = DB::table('registrations')
                    ->where('payment_tran_id', $tranId)
                    ->first();
            }

            if (!$registration) {
                Log::warning('No period/registration found for tran_id', ['tran_id' => $tranId]);
                DB::commit(); // nothing to update
                return response()->json(['ack' => 'ok']);
            }

            // If you still have old columns, update them (backward compatibility)
            if (DB::getSchemaBuilder()->hasColumn('registrations', 'payment_status')) {
                $updateReg = [
                    'payment_status' => $status,
                    'updated_at' => now(),
                ];
                if (DB::getSchemaBuilder()->hasColumn('registrations', 'payment_date')) {
                    $updateReg['payment_date'] = now();
                }

                DB::table('registrations')
                    ->where('id', $registration->id)
                    ->update($updateReg);
            }

            // ✅ Also update academic period based on registration->academic_year + semester (best effort)
            $studentLink = $this->findStudentByRegistrationOrContact(
                (int) $registration->id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if ($studentLink) {
                $semester = 1;
                if (isset($registration->semester) && in_array((int)$registration->semester, [1,2], true)) {
                    $semester = (int)$registration->semester;
                }

                $update = [
                    'payment_status' => $status,
                    'updated_at' => now(),
                ];
                if ($status === 'PAID') {
                    $update['paid_at'] = now();
                }

                DB::table('student_academic_periods')
                    ->where('student_id', $studentLink->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $semester)
                    ->update($update);

                if ($status === 'PAID') {
                    User::where('email', $registration->personal_email)
                        ->where('role', 'register')
                        ->update(['role' => 'student']);
                }
            }

            DB::commit();
            return response()->json(['ack' => 'ok']);
        } catch (\Throwable $e) {
            DB::rollBack();

            // ✅ ABA still must receive 200
            Log::error('paymentCallback error', [
                'tran_id' => $tranId,
                'error' => $e->getMessage()
            ]);

            return response()->json(['ack' => 'ok']);
        }
    }
}
