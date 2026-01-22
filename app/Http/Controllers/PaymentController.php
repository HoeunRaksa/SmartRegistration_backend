<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\Major;

class PaymentController extends Controller
{
    /* ============================================================
     * ✅ FIX 1: generateQr saves tran_id to correct period row
     * ✅ FIX 2: callback updates SAP even if tran_id not found (fallback parse)
     * ✅ FIX 3: support pay plan: SEMESTER = 50% fee, YEAR = 100% fee
     * ============================================================ */

    private function normalizeSemester($semester): int
    {
        $s = (int) ($semester ?? 1);
        return in_array($s, [1, 2], true) ? $s : 1;
    }

    private function normalizePayPlan(?array $payPlan, int $semester): array
    {
        $type = strtoupper((string) ($payPlan['type'] ?? 'SEMESTER'));
        if (!in_array($type, ['SEMESTER', 'YEAR'], true)) $type = 'SEMESTER';

        $sem = $this->normalizeSemester($payPlan['semester'] ?? $semester);

        return [
            'type' => $type,
            'semester' => $sem,
        ];
    }

    private function findStudentByRegistrationOrContact(int $registrationId, ?string $email, ?string $phone)
    {
        $student = DB::table('students')
            ->where('registration_id', $registrationId)
            ->select('id', 'student_code', 'user_id', 'registration_id', 'department_id')
            ->first();

        if ($student) return $student;

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

    private function upsertAcademicPeriodSafe(int $studentId, string $academicYear, int $semester, array $data): void
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

    private function ensureAcademicPeriodNoReset(int $studentId, string $academicYear, int $semester, float $tuitionAmount): void
    {
        $existing = DB::table('student_academic_periods')
            ->where('student_id', $studentId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->first();

        if ($existing) {
            DB::table('student_academic_periods')->where('id', $existing->id)->update([
                'status' => 'ACTIVE',
                'tuition_amount' => $tuitionAmount,
                'updated_at' => now(),
            ]);
            return;
        }

        DB::table('student_academic_periods')->insert([
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'status' => 'ACTIVE',
            'tuition_amount' => $tuitionAmount,
            'payment_status' => 'PENDING',
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function parseTranId(string $tranId): array
    {
        // Expected:
        // SEMESTER: REG-{regId}-S{sem}-{timestamp}
        // YEAR:     REG-{regId}-Y-{timestamp}
        $out = [
            'registration_id' => null,
            'type' => null,
            'semester' => null,
        ];

        if (preg_match('/^REG-(\d+)-S([12])-\d+$/', $tranId, $m)) {
            $out['registration_id'] = (int) $m[1];
            $out['type'] = 'SEMESTER';
            $out['semester'] = (int) $m[2];
            return $out;
        }

        if (preg_match('/^REG-(\d+)-Y-\d+$/', $tranId, $m)) {
            $out['registration_id'] = (int) $m[1];
            $out['type'] = 'YEAR';
            $out['semester'] = null;
            return $out;
        }

        return $out;
    }

    public function generateQr(Request $request)
    {
        Log::info('🔥 generateQr hit');

        $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'semester' => 'nullable|integer|in:1,2',
            'pay_plan' => 'nullable|array',
            'pay_plan.type' => 'nullable|string',     // SEMESTER | YEAR
            'pay_plan.semester' => 'nullable|integer|in:1,2',
        ]);

        $semester = $this->normalizeSemester($request->input('semester', 1));
        $payPlan = $this->normalizePayPlan($request->input('pay_plan', null), $semester);

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

            $student = $this->findStudentByRegistrationOrContact(
                (int) $registration->id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if (!$student) {
                DB::rollBack();
                return response()->json(['error' => 'Student not found for this registration'], 404);
            }

            $yearFee = (float) ($registration->registration_fee ?? 0);
            $amountFloat = $payPlan['type'] === 'YEAR'
                ? $yearFee
                : ($yearFee * 0.5);

            $amount = number_format((float) $amountFloat, 2, '.', '');

            // ✅ Ensure academic period exists without resetting payment
            // If YEAR plan: ensure both semesters exist
            if ($payPlan['type'] === 'YEAR') {
                $this->ensureAcademicPeriodNoReset((int) $student->id, (string) $registration->academic_year, 1, $yearFee * 0.5);
                $this->ensureAcademicPeriodNoReset((int) $student->id, (string) $registration->academic_year, 2, $yearFee * 0.5);

                // If already paid BOTH semesters => block
                $p1 = DB::table('student_academic_periods')
                    ->where('student_id', $student->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', 1)
                    ->first();

                $p2 = DB::table('student_academic_periods')
                    ->where('student_id', $student->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', 2)
                    ->first();

                if ($p1 && $p2 && strtoupper((string)$p1->payment_status) === 'PAID' && strtoupper((string)$p2->payment_status) === 'PAID') {
                    DB::rollBack();
                    return response()->json(['error' => 'Already paid full year.'], 409);
                }
            } else {
                $this->ensureAcademicPeriodNoReset((int) $student->id, (string) $registration->academic_year, (int) $payPlan['semester'], $amountFloat);

                $period = DB::table('student_academic_periods')
                    ->where('student_id', $student->id)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $payPlan['semester'])
                    ->first();

                if ($period && strtoupper((string) $period->payment_status) === 'PAID') {
                    DB::rollBack();
                    return response()->json(['error' => 'Already paid for this academic year and semester.'], 409);
                }
            }

            /* ================= REQUIRED FIELDS ================= */

            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');

            // ✅ tranId includes plan so callback can fallback parse if needed
            $tranId = $payPlan['type'] === 'YEAR'
                ? ('REG-' . $registration->id . '-Y-' . time())
                : ('REG-' . $registration->id . '-S' . $payPlan['semester'] . '-' . time());

            // payment_transactions record
            $txData = [
                'amount' => $amount,
                'status' => 'PENDING',
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('payment_transactions', 'created_at')) {
                $txData['created_at'] = now();
            }

            if (Schema::hasColumn('payment_transactions', 'student_id')) $txData['student_id'] = (int) $student->id;
            if (Schema::hasColumn('payment_transactions', 'academic_year')) $txData['academic_year'] = (string) $registration->academic_year;
            if (Schema::hasColumn('payment_transactions', 'semester')) $txData['semester'] = $payPlan['type'] === 'YEAR' ? null : (int) $payPlan['semester'];
            if (Schema::hasColumn('payment_transactions', 'pay_plan_type')) $txData['pay_plan_type'] = $payPlan['type'];

            DB::table('payment_transactions')->updateOrInsert(
                ['tran_id' => $tranId],
                $txData
            );

            // ✅ Save tran_id into period rows (if column exists)
            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                if ($payPlan['type'] === 'YEAR') {
                    DB::table('student_academic_periods')
                        ->where('student_id', $student->id)
                        ->where('academic_year', $registration->academic_year)
                        ->whereIn('semester', [1, 2])
                        ->update([
                            'tran_id' => $tranId,
                            'updated_at' => now(),
                        ]);
                } else {
                    DB::table('student_academic_periods')
                        ->where('student_id', $student->id)
                        ->where('academic_year', $registration->academic_year)
                        ->where('semester', $payPlan['semester'])
                        ->update([
                            'tran_id' => $tranId,
                            'updated_at' => now(),
                        ]);
                }
            }

            $currency = 'USD';

            /* ================= OPTIONAL PAYER INFO ================= */

            $firstName = trim($registration->first_name ?? '');
            $lastName  = trim($registration->last_name ?? '');
            $email     = trim($registration->personal_email ?? '');
            $phone     = preg_replace('/\D/', '', $registration->phone_number ?? '');

            /* ================= BASE64 FIELDS ================= */

            $items = base64_encode(json_encode([[
                'name'     => ($payPlan['type'] === 'YEAR') ? 'Tuition Fee (Full Year)' : ('Tuition Fee (Semester ' . $payPlan['semester'] . ')'),
                'quantity' => 1,
                'price'    => $amount
            ]], JSON_UNESCAPED_SLASHES));

            $callbackUrl     = base64_encode(config('payway.callback'));
            $returnDeeplink  = '';
            $customFields    = '';
            $returnParams    = '';
            $payout          = '';
            $lifetime        = 6;
            $qrImageTemplate = 'template3_color';
            $purchaseType    = 'purchase';
            $paymentOption   = 'abapay_khqr';

            /* ================= HASH STRING ================= */

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

            $hash = base64_encode(hash_hmac('sha512', $hashString, config('payway.api_key'), true));

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

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post('https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/generate-qr', $payload);

            if (!$response->successful()) {
                DB::rollBack();
                Log::error('ABA QR Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return response()->json($response->json(), 403);
            }

            DB::commit();

            return response()->json([
                'tran_id' => $tranId,
                'qr'      => $response->json(),
                'meta'    => [
                    'registration_id' => (int) $registration->id,
                    'student_id'      => (int) $student->id,
                    'academic_year'   => (string) $registration->academic_year,
                    'pay_plan'        => $payPlan,
                    'amount'          => $amount,
                ]
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('generateQr error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'failed', 'message' => $e->getMessage()], 500);
        }
    }

    public function paymentCallback(Request $request)
    {
        Log::info('ABA CALLBACK RECEIVED', $request->all());

        $tranId = (string) ($request->input('tran_id') ?? '');
        if ($tranId === '') {
            return response()->json(['ack' => 'ok']); // ABA needs 200
        }

        // Robust status detect
        $code = (string) (
            $request->input('payment_status_code')
            ?? data_get($request->all(), 'status.code')
            ?? $request->input('status')
            ?? ''
        );

        $status = ($code === '0') ? 'PAID' : 'FAILED';

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

            // 1) Best: find by tran_id in SAP
            $periods = collect();

            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                $periods = DB::table('student_academic_periods')
                    ->where('tran_id', $tranId)
                    ->get();
            }

            // 2) Fallback: parse tranId to locate student+year+semester
            if ($periods->count() === 0) {
                $parsed = $this->parseTranId($tranId);
                if (!empty($parsed['registration_id'])) {
                    $reg = DB::table('registrations')->where('id', $parsed['registration_id'])->first();
                    if ($reg) {
                        $student = $this->findStudentByRegistrationOrContact(
                            (int) $reg->id,
                            $reg->personal_email ?? null,
                            $reg->phone_number ?? null
                        );

                        if ($student) {
                            if ($parsed['type'] === 'YEAR') {
                                $periods = DB::table('student_academic_periods')
                                    ->where('student_id', $student->id)
                                    ->where('academic_year', $reg->academic_year)
                                    ->whereIn('semester', [1, 2])
                                    ->get();
                            } elseif ($parsed['type'] === 'SEMESTER' && !empty($parsed['semester'])) {
                                $periods = DB::table('student_academic_periods')
                                    ->where('student_id', $student->id)
                                    ->where('academic_year', $reg->academic_year)
                                    ->where('semester', (int)$parsed['semester'])
                                    ->get();
                            }
                        }
                    }
                }
            }

            // Update periods
            if ($periods->count() > 0) {
                foreach ($periods as $p) {
                    $upd = [
                        'payment_status' => $status,
                        'updated_at' => now(),
                    ];
                    if ($status === 'PAID') $upd['paid_at'] = now();

                    DB::table('student_academic_periods')->where('id', $p->id)->update($upd);

                    // upgrade role on PAID
                    if ($status === 'PAID') {
                        $studentRow = DB::table('students')->where('id', $p->student_id)->first();
                        if ($studentRow) {
                            User::where('id', $studentRow->user_id)
                                ->where('role', 'register')
                                ->update(['role' => 'student']);
                        }
                    }
                }
            }

            DB::commit();
            return response()->json(['ack' => 'ok']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('paymentCallback error', ['tran_id' => $tranId, 'error' => $e->getMessage()]);
            return response()->json(['ack' => 'ok']); // ABA must get 200
        }
    }
}
