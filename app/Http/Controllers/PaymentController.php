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
            ->select(
                'students.id as student_id',
                'students.student_code',
                'students.user_id',
                'students.registration_id',
                'students.department_id'
            )
            ->first();

        if ($student) return $student;

        return DB::table('students')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('registrations', 'students.registration_id', '=', 'registrations.id')
            ->where(function ($q) use ($email, $phone) {
                $hasAny = false;

                if (!empty($email)) {
                    $hasAny = true;
                    $q->where('users.email', $email)
                        ->orWhere('registrations.personal_email', $email);
                }

                if (!empty($phone)) {
                    if ($hasAny) $q->orWhere('registrations.phone_number', $phone);
                    else $q->where('registrations.phone_number', $phone);
                }
            })
            ->select(
                'students.id as student_id',
                'students.student_code',
                'students.user_id',
                'students.registration_id',
                'students.department_id'
            )
            ->orderByDesc('students.id')
            ->first();
    }


    private function assertMajorCanPayOrFail(int $majorId, string $academicYear): void
    {
        $quota = DB::table('major_quotas')
            ->where('major_id', $majorId)
            ->where('academic_year', $academicYear)
            ->lockForUpdate()
            ->first();

        // No quota row => unlimited
        if (!$quota) return;

        $now = now();

        // optional open/close window
        if (!empty($quota->opens_at) && $now->lt($quota->opens_at)) {
            throw new \RuntimeException('PAYMENT_NOT_OPEN');
        }

        if (!empty($quota->closes_at) && $now->gt($quota->closes_at)) {
            throw new \RuntimeException('PAYMENT_CLOSED');
        }

        $limit = (int) ($quota->limit ?? 0);
        if ($limit <= 0) return; // treat <=0 as unlimited

        // Count PAID seats (distinct students) for that major + academic year
        $paidCount = (int) DB::table('student_academic_periods as sap')
            ->join('students as s', 's.id', '=', 'sap.student_id')
            ->join('registrations as r', 'r.id', '=', 's.registration_id')
            ->where('r.major_id', $majorId)
            ->where('sap.academic_year', $academicYear)
            ->where('sap.payment_status', 'PAID')
            ->distinct('sap.student_id')
            ->count('sap.student_id');

        if ($paidCount >= $limit) {
            throw new \RuntimeException('MAJOR_FULL');
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
        Log::info('🔥 generateQr hit', ['payload' => $request->all()]);

        // ✅ Don’t use exists:registrations,id (it returns confusing “invalid”)
        $request->validate([
            'registration_id' => 'required|integer|min:1',
            'semester' => 'nullable|integer|in:1,2',
            'pay_plan' => 'nullable|array',
            'pay_plan.type' => 'nullable|string',
            'pay_plan.semester' => 'nullable|integer|in:1,2',
        ]);

        $semester = $this->normalizeSemester($request->input('semester', 1));
        $payPlan  = $this->normalizePayPlan($request->input('pay_plan', null), $semester);

        DB::beginTransaction();

        try {
            // ✅ Get registration + fee
            $registration = DB::table('registrations')
                ->join('majors', 'registrations.major_id', '=', 'majors.id')
                ->where('registrations.id', (int)$request->registration_id)
                ->select('registrations.*', 'majors.registration_fee')
                ->first();

            if (!$registration) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Registration not found. Use registration_id returned from /api/register/save',
                    'registration_id' => (int)$request->registration_id,
                ], 404);
            }

            // ✅ Find student (NO create here)
            $student = $this->findStudentByRegistrationOrContact(
                (int)$registration->id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if (!$student) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Student not found for this registration. Make sure registration created student successfully.',
                    'registration_id' => (int)$registration->id,
                ], 404);
            }

            // ✅ IMPORTANT FIX: correct student id field name
            $studentId = (int)$student->student_id;

            $yearFee = (float)($registration->registration_fee ?? 0);
            if ($yearFee <= 0) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Invalid registration fee'], 422);
            }

            $amountFloat = ($payPlan['type'] === 'YEAR') ? $yearFee : ($yearFee * 0.5);
            $amount      = number_format((float)$amountFloat, 2, '.', '');

            // ✅ Ensure academic period exists WITHOUT resetting PAID
            if ($payPlan['type'] === 'YEAR') {
                $this->ensureAcademicPeriodNoReset($studentId, (string)$registration->academic_year, 1, $yearFee * 0.5);
                $this->ensureAcademicPeriodNoReset($studentId, (string)$registration->academic_year, 2, $yearFee * 0.5);

                $p1 = DB::table('student_academic_periods')
                    ->where('student_id', $studentId)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', 1)
                    ->first();

                $p2 = DB::table('student_academic_periods')
                    ->where('student_id', $studentId)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', 2)
                    ->first();

                if ($p1 && $p2 && strtoupper((string)$p1->payment_status) === 'PAID' && strtoupper((string)$p2->payment_status) === 'PAID') {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Already paid full year.'], 409);
                }
            } else {
                $this->ensureAcademicPeriodNoReset($studentId, (string)$registration->academic_year, (int)$payPlan['semester'], $amountFloat);

                $period = DB::table('student_academic_periods')
                    ->where('student_id', $studentId)
                    ->where('academic_year', $registration->academic_year)
                    ->where('semester', $payPlan['semester'])
                    ->first();

                if ($period && strtoupper((string)$period->payment_status) === 'PAID') {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Already paid for this academic year and semester.'], 409);
                }
            }

            /* ================= REQUIRED FIELDS ================= */
            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');

            $tranId = $payPlan['type'] === 'YEAR'
                ? ('REG-' . $registration->id . '-Y-' . time())
                : ('REG-' . $registration->id . '-S' . $payPlan['semester'] . '-' . time());

            // ✅ Save transaction
            $txData = [
                'amount' => $amount,
                'status' => 'PENDING',
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('payment_transactions', 'created_at')) {
                $txData['created_at'] = now();
            }
            if (Schema::hasColumn('payment_transactions', 'student_id')) $txData['student_id'] = $studentId;
            if (Schema::hasColumn('payment_transactions', 'academic_year')) $txData['academic_year'] = (string)$registration->academic_year;
            if (Schema::hasColumn('payment_transactions', 'semester')) $txData['semester'] = $payPlan['type'] === 'YEAR' ? null : (int)$payPlan['semester'];
            if (Schema::hasColumn('payment_transactions', 'pay_plan_type')) $txData['pay_plan_type'] = $payPlan['type'];

            DB::table('payment_transactions')->updateOrInsert(
                ['tran_id' => $tranId],
                $txData
            );

            // ✅ Save tran_id into periods (if column exists)
            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                if ($payPlan['type'] === 'YEAR') {
                    DB::table('student_academic_periods')
                        ->where('student_id', $studentId)
                        ->where('academic_year', $registration->academic_year)
                        ->whereIn('semester', [1, 2])
                        ->update(['tran_id' => $tranId, 'updated_at' => now()]);
                } else {
                    DB::table('student_academic_periods')
                        ->where('student_id', $studentId)
                        ->where('academic_year', $registration->academic_year)
                        ->where('semester', $payPlan['semester'])
                        ->update(['tran_id' => $tranId, 'updated_at' => now()]);
                }
            }

            $currency = 'USD';

            $firstName = trim($registration->first_name ?? '');
            $lastName  = trim($registration->last_name ?? '');
            $email     = trim($registration->personal_email ?? '');
            $phone     = preg_replace('/\D/', '', $registration->phone_number ?? '');

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
                    'registration_id' => (int)$registration->id,
                    'student_id'      => (int)$studentId,
                    'academic_year'   => (string)$registration->academic_year,
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
        Log::info('ABA CALLBACK RECEIVED', ['payload' => $request->all()]);

        $tranId = (string) ($request->input('tran_id') ?? '');
        if ($tranId === '') {
            // ABA needs HTTP 200 always
            return response()->json(['ack' => 'ok']);
        }

        /*
    |------------------------------------------------------------
    | Robust status detection
    | ABA commonly uses payment_status_code = "0" for success
    | Some payloads may include nested status.code or status field
    |------------------------------------------------------------
    */
        $code = (string) (
            $request->input('payment_status_code')
            ?? data_get($request->all(), 'status.code')
            ?? $request->input('status')
            ?? ''
        );

        $status = ($code === '0' || strtoupper($code) === 'SUCCESS') ? 'PAID' : 'FAILED';

        /*
    |------------------------------------------------------------
    | Upsert payment transaction log (DO NOT overwrite created_at)
    |------------------------------------------------------------
    */
        $existingTx = DB::table('payment_transactions')->where('tran_id', $tranId)->first();

        if ($existingTx) {
            DB::table('payment_transactions')
                ->where('tran_id', $tranId)
                ->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);
        } else {
            $insert = [
                'tran_id' => $tranId,
                'status' => $status,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('payment_transactions', 'created_at')) {
                $insert['created_at'] = now();
            }
            DB::table('payment_transactions')->insert($insert);
        }

        // If FAILED => keep 200 OK, do nothing else
        if ($status !== 'PAID') {
            return response()->json(['ack' => 'ok']);
        }

        try {
            DB::beginTransaction();

            /*
        |------------------------------------------------------------
        | 1) Locate student_academic_periods by tran_id (best path)
        |------------------------------------------------------------
        */
            $periods = collect();

            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                $periods = DB::table('student_academic_periods')
                    ->where('tran_id', $tranId)
                    ->get();
            }

            /*
        |------------------------------------------------------------
        | 2) Fallback: parse tranId => registration => student => periods
        | Requires your existing helpers:
        | - parseTranId($tranId)
        | - findStudentByRegistrationOrContact($regId, $email, $phone)
        |------------------------------------------------------------
        */
            $reg = null;
            $student = null;

            if ($periods->count() === 0) {
                $parsed = $this->parseTranId($tranId);

                if (!empty($parsed['registration_id'])) {
                    $reg = DB::table('registrations')
                        ->where('id', (int) $parsed['registration_id'])
                        ->first();

                    if ($reg) {
                        $student = $this->findStudentByRegistrationOrContact(
                            (int) $reg->id,
                            $reg->personal_email ?? null,
                            $reg->phone_number ?? null
                        );

                        // ✅ IMPORTANT: use student_id (same as generateQr)
                        $studentId = $student ? (int) ($student->student_id ?? 0) : 0;

                        if ($studentId > 0) {
                            if (($parsed['type'] ?? '') === 'YEAR') {
                                $periods = DB::table('student_academic_periods')
                                    ->where('student_id', $studentId)
                                    ->where('academic_year', (string) $reg->academic_year)
                                    ->whereIn('semester', [1, 2])
                                    ->get();
                            } elseif (($parsed['type'] ?? '') === 'SEMESTER' && !empty($parsed['semester'])) {
                                $periods = DB::table('student_academic_periods')
                                    ->where('student_id', $studentId)
                                    ->where('academic_year', (string) $reg->academic_year)
                                    ->where('semester', (int) $parsed['semester'])
                                    ->get();
                            }
                        }
                    }
                }
            }

            // Still no periods found => nothing to update, but ACK OK
            if ($periods->count() === 0) {
                DB::commit();
                return response()->json(['ack' => 'ok']);
            }

            /*
        |------------------------------------------------------------
        | Determine major_id + academic_year for quota/window check
        |------------------------------------------------------------
        */
            $majorId = null;
            $academicYear = null;

            if ($reg) {
                $majorId = (int) ($reg->major_id ?? 0);
                $academicYear = (string) ($reg->academic_year ?? '');
            } else {
                // derive from first period => student => registration
                $first = $periods->first();

                // ✅ IMPORTANT: students table lookup must use student_id
                $studentRow = DB::table('students')
                    ->where('student_id', (int) ($first->student_id ?? 0))
                    ->first();

                if ($studentRow) {
                    $reg = DB::table('registrations')
                        ->where('id', (int) ($studentRow->registration_id ?? 0))
                        ->first();

                    if ($reg) {
                        $majorId = (int) ($reg->major_id ?? 0);
                        $academicYear = (string) ($reg->academic_year ?? '');
                    }
                }
            }

            /*
        |------------------------------------------------------------
        | QUOTA CHECK (only when PAID)
        | Requires your existing helper:
        | - assertMajorCanPayOrFail($majorId, $academicYear)
        | Throws RuntimeException with: MAJOR_FULL / PAYMENT_NOT_OPEN / PAYMENT_CLOSED
        |------------------------------------------------------------
        */
            if (!empty($majorId) && !empty($academicYear)) {
                try {
                    $this->assertMajorCanPayOrFail((int) $majorId, (string) $academicYear);
                } catch (\RuntimeException $ex) {
                    $reason = $ex->getMessage(); // MAJOR_FULL / PAYMENT_NOT_OPEN / PAYMENT_CLOSED

                    DB::table('payment_transactions')
                        ->where('tran_id', $tranId)
                        ->update([
                            'status' => $reason === 'MAJOR_FULL' ? 'REJECTED_FULL' : 'REJECTED',
                            'updated_at' => now(),
                        ]);

                    DB::commit();

                    Log::warning('Payment rejected by quota/window', [
                        'tran_id' => $tranId,
                        'reason' => $reason,
                        'major_id' => $majorId,
                        'academic_year' => $academicYear,
                    ]);

                    // ABA must get 200
                    return response()->json(['ack' => 'ok']);
                }
            }

            /*
        |------------------------------------------------------------
        | Update periods to PAID (idempotent)
        | Upgrade user role register => student
        |------------------------------------------------------------
        */
            foreach ($periods as $p) {
                $alreadyPaid = strtoupper((string) ($p->payment_status ?? '')) === 'PAID';
                if ($alreadyPaid) continue;

                DB::table('student_academic_periods')
                    ->where('id', (int) $p->id)
                    ->update([
                        'payment_status' => 'PAID',
                        'paid_at' => now(),
                        'updated_at' => now(),
                    ]);

                // ✅ IMPORTANT: students lookup by student_id
                $studentRow = DB::table('students')
                    ->where('student_id', (int) ($p->student_id ?? 0))
                    ->first();

                if ($studentRow && !empty($studentRow->user_id)) {
                    User::where('id', (int) $studentRow->user_id)
                        ->where('role', 'register')
                        ->update(['role' => 'student']);
                }
            }

            DB::commit();
            return response()->json(['ack' => 'ok']); // ABA must get 200
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('paymentCallback error', [
                'tran_id' => $tranId,
                'error' => $e->getMessage(),
            ]);

            // ABA must get 200 always
            return response()->json(['ack' => 'ok']);
        }
    }
}
