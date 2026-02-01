<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Services\ClassGroupAllocator;
use App\Services\EnrollmentService;

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

        if (!$quota) return;

        $now = now();

        if (!empty($quota->opens_at) && $now->lt($quota->opens_at)) {
            throw new \RuntimeException('PAYMENT_NOT_OPEN');
        }

        if (!empty($quota->closes_at) && $now->gt($quota->closes_at)) {
            throw new \RuntimeException('PAYMENT_CLOSED');
        }

        $limit = (int) ($quota->limit ?? 0);
        if ($limit <= 0) return;

        $paidCount = (int) DB::table('student_academic_periods as sap')
            ->join('students as s', 's.id', '=', 'sap.student_id')        // sap.student_id = students.id
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

    // ✅ never reset payment_status/paid_at/tran_id
    private function ensureAcademicPeriodNoReset(
        int $studentId,
        string $academicYear,
        int $semester,
        float $tuitionAmount
    ): void {
        $semester = $this->normalizeSemester($semester);

        $row = DB::table('student_academic_periods')
            ->where('student_id', $studentId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->first();

        if ($row) {
            DB::table('student_academic_periods')
                ->where('id', $row->id)
                ->update([
                    'status' => 'ACTIVE',
                    'tuition_amount' => $tuitionAmount,
                    'updated_at' => now(),
                ]);
            return;
        }

        $insert = [
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'status' => 'ACTIVE',
            'tuition_amount' => $tuitionAmount,
            'payment_status' => 'PENDING',
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // ✅ only if column exists
        if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
            $insert['tran_id'] = null;
        }

        DB::table('student_academic_periods')->insert($insert);
    }

    private function parseTranId(string $tranId): array
    {
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
            $registration = DB::table('registrations')
                ->join('majors', 'registrations.major_id', '=', 'majors.id')
                ->where('registrations.id', (int)$request->registration_id)
                ->select('registrations.*', 'majors.registration_fee')
                ->first();

            if (!$registration) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Registration not found.',
                    'registration_id' => (int)$request->registration_id,
                ], 404);
            }

            $student = $this->findStudentByRegistrationOrContact(
                (int)$registration->id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if (!$student) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Student not found for this registration.',
                    'registration_id' => (int)$registration->id,
                ], 404);
            }

            $studentId = (int)$student->student_id;

            $yearFee = (float)($registration->registration_fee ?? 0);
            if ($yearFee <= 0) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Invalid registration fee'], 422);
            }

            $amountFloat = ($payPlan['type'] === 'YEAR') ? $yearFee : ($yearFee * 0.5);
            $amount      = number_format((float)$amountFloat, 2, '.', '');

            // ✅ ensure periods exist (never reset)
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

                if ($p1 && $p2
                    && strtoupper((string)$p1->payment_status) === 'PAID'
                    && strtoupper((string)$p2->payment_status) === 'PAID') {
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
                    return response()->json(['success' => false, 'message' => 'Already paid for this semester.'], 409);
                }
            }

            $reqTime    = now()->utc()->format('YmdHis');
            $merchantId = config('payway.merchant_id');

            $tranId = $payPlan['type'] === 'YEAR'
                ? ('REG-' . $registration->id . '-Y-' . time())
                : ('REG-' . $registration->id . '-S' . $payPlan['semester'] . '-' . time());

            $txData = [
                'amount' => $amount,
                'status' => 'PENDING',
                'updated_at' => now(),
            ];

            if (Schema::hasColumn('payment_transactions', 'created_at')) $txData['created_at'] = now();
            if (Schema::hasColumn('payment_transactions', 'student_id')) $txData['student_id'] = $studentId;
            if (Schema::hasColumn('payment_transactions', 'academic_year')) $txData['academic_year'] = (string)$registration->academic_year;
            if (Schema::hasColumn('payment_transactions', 'semester')) $txData['semester'] = $payPlan['type'] === 'YEAR' ? null : (int)$payPlan['semester'];
            if (Schema::hasColumn('payment_transactions', 'pay_plan_type')) $txData['pay_plan_type'] = $payPlan['type'];

            DB::table('payment_transactions')->updateOrInsert(['tran_id' => $tranId], $txData);

            // ✅ write tran_id into periods only if column exists
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
                Log::error('ABA QR Error', ['status' => $response->status(), 'body' => $response->body()]);
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

        $tranId = (string)($request->input('tran_id') ?? '');
        if ($tranId === '') {
            return response()->json(['ack' => 'ok']);
        }

        $code = (string)(
            $request->input('payment_status_code')
            ?? data_get($request->all(), 'status.code')
            ?? $request->input('status')
            ?? ''
        );

        $codeU = strtoupper(trim($code));
        $status = ($code === '0' || $codeU === 'SUCCESS') ? 'PAID' : 'FAILED';

        // ✅ upsert tx (do not overwrite created_at)
        $existingTx = DB::table('payment_transactions')->where('tran_id', $tranId)->first();
        if ($existingTx) {
            DB::table('payment_transactions')->where('tran_id', $tranId)->update([
                'status' => $status,
                'updated_at' => now(),
            ]);
        } else {
            $insert = [
                'tran_id' => $tranId,
                'status' => $status,
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('payment_transactions', 'created_at')) $insert['created_at'] = now();
            DB::table('payment_transactions')->insert($insert);
        }

        if ($status !== 'PAID') {
            return response()->json(['ack' => 'ok']);
        }

        try {
            DB::beginTransaction();

            $parsed = $this->parseTranId($tranId);
            $periods = collect();
            $reg = null;

            // 1) try by tran_id
            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                $periods = DB::table('student_academic_periods')
                    ->where('tran_id', $tranId)
                    ->get();
            }

            // 2) load reg + student if possible (ALWAYS helpful)
            if (!empty($parsed['registration_id'])) {
                $reg = DB::table('registrations')->where('id', (int)$parsed['registration_id'])->first();
            }

            $studentId = 0;
            if ($reg) {
                $student = $this->findStudentByRegistrationOrContact(
                    (int)$reg->id,
                    $reg->personal_email ?? null,
                    $reg->phone_number ?? null
                );
                $studentId = $student ? (int)($student->student_id ?? 0) : 0;
            }

            // ✅ YEAR: ensure ALWAYS have both periods (prevents "paid only 1 semester")
            if (($parsed['type'] ?? '') === 'YEAR' && $reg && $studentId > 0) {
                if ($periods->count() < 2) {
                    $this->ensureAcademicPeriodNoReset($studentId, (string)$reg->academic_year, 1, 0);
                    $this->ensureAcademicPeriodNoReset($studentId, (string)$reg->academic_year, 2, 0);
                }

                $periods = DB::table('student_academic_periods')
                    ->where('student_id', $studentId)
                    ->where('academic_year', (string)$reg->academic_year)
                    ->whereIn('semester', [1, 2])
                    ->get();
            }

            // SEMESTER: if no periods found, load by student/year/semester
            if ($periods->count() === 0 && $reg && $studentId > 0) {
                $sem = ($parsed['type'] ?? '') === 'SEMESTER'
                    ? $this->normalizeSemester((int)($parsed['semester'] ?? 1))
                    : 1;

                $periods = DB::table('student_academic_periods')
                    ->where('student_id', $studentId)
                    ->where('academic_year', (string)$reg->academic_year)
                    ->where('semester', $sem)
                    ->get();
            }

            if ($periods->count() === 0) {
                DB::commit();
                return response()->json(['ack' => 'ok']);
            }

            // ✅ Ensure reg loaded even if parsed missing
            if (!$reg) {
                $studentIdFromPeriod = (int)($periods->first()->student_id ?? 0);
                $studentRow = DB::table('students')->where('id', $studentIdFromPeriod)->first();
                if ($studentRow && !empty($studentRow->registration_id)) {
                    $reg = DB::table('registrations')->where('id', (int)$studentRow->registration_id)->first();
                }
                $studentId = $studentIdFromPeriod;
            }

            $majorId = $reg ? (int)($reg->major_id ?? 0) : 0;
            $academicYear = $reg ? (string)($reg->academic_year ?? '') : '';
            $shift = $reg ? ($reg->shift ?? null) : null;

            if ($majorId > 0 && $academicYear !== '') {
                try {
                    $this->assertMajorCanPayOrFail($majorId, $academicYear);
                } catch (\RuntimeException $ex) {
                    $reason = $ex->getMessage();

                    DB::table('payment_transactions')->where('tran_id', $tranId)->update([
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

                    return response()->json(['ack' => 'ok']);
                }
            }

            // ✅ mark periods paid
            foreach ($periods as $p) {
                if (strtoupper((string)($p->payment_status ?? '')) === 'PAID') continue;

                DB::table('student_academic_periods')->where('id', (int)$p->id)->update([
                    'payment_status' => 'PAID',
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

                // upgrade role
                $studentRow = DB::table('students')->where('id', (int)$p->student_id)->first();
                if ($studentRow && !empty($studentRow->user_id)) {
                    User::where('id', (int)$studentRow->user_id)
                        ->where('role', 'register')
                        ->update(['role' => 'student']);
                }
            }

            // ✅ auto assign class group
            if ($majorId > 0 && $academicYear !== '' && $studentId > 0) {
                $allocator = app(ClassGroupAllocator::class);
                $enrollService = app(EnrollmentService::class);

                if (($parsed['type'] ?? '') === 'YEAR') {
                    foreach ([1, 2] as $sem) {
                        $group = $allocator->getOrCreateAvailableGroup($majorId, $academicYear, $sem, $shift, 40);
                        $allocator->assignStudentToGroup($studentId, (int)$group->id, $academicYear, $sem);
                        
                        // 🔥 AUTO ENROLL COURSES
                        $enrollService->autoEnrollStudent($studentId, $majorId, $academicYear, $sem, (int)$group->id);
                    }
                } else {
                    $sem = $this->normalizeSemester((int)($parsed['semester'] ?? 1));
                    $group = $allocator->getOrCreateAvailableGroup($majorId, $academicYear, $sem, $shift, 40);
                    $allocator->assignStudentToGroup($studentId, (int)$group->id, $academicYear, $sem);

                    // 🔥 AUTO ENROLL COURSES
                    $enrollService->autoEnrollStudent($studentId, $majorId, $academicYear, $sem, (int)$group->id);
                }
            }

            DB::commit();
            return response()->json(['ack' => 'ok']);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('paymentCallback error', ['tran_id' => $tranId, 'error' => $e->getMessage()]);
            return response()->json(['ack' => 'ok']);
        }
    }
    public function getStudentPayments(Request $request)
    {
        try {
            $user = $request->user();
            $student = DB::table('students')->where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student record not found.'
                ], 404);
            }

            $studentId = $student->id;

            // 1. Fetch Academic Periods (Dues/Billing)
            $periods = DB::table('student_academic_periods')
                ->where('student_id', $studentId)
                ->orderByDesc('academic_year')
                ->orderByDesc('semester')
                ->get();

            // 2. Fetch specific Transactions (QR/ABA history)
            // Robust check: use registration_id in tran_id since student_id might be missing
            $regId = $student->registration_id;
            $transactionsQuery = DB::table('payment_transactions');
            
            if (Schema::hasColumn('payment_transactions', 'student_id')) {
                $transactionsQuery->where('student_id', $studentId);
            } else {
                // Fallback to tran_id prefix: REG-{id}-
                $transactionsQuery->where('tran_id', 'LIKE', "REG-{$regId}-%");
            }

            $transactions = $transactionsQuery->orderByDesc('updated_at')->get();

            $ledger = [];

            // Add periods as primary ledger items
            foreach ($periods as $p) {
                $ledger[] = [
                    'id' => 'PER-' . $p->id,
                    'amount' => (float)($p->tuition_amount ?? 0),
                    'status' => strtoupper($p->payment_status ?? 'PENDING'),
                    'payment_status' => $p->payment_status,
                    'method' => $p->payment_status === 'PAID' ? 'Verified' : 'Pending Action',
                    'description' => "Tuition - Year {$p->academic_year} (Sem {$p->semester})",
                    'date' => $p->paid_at ?? $p->created_at ?? $p->updated_at,
                    'type' => 'PERIOD',
                    'academic_year' => $p->academic_year,
                    'semester' => $p->semester,
                    'tran_id' => $p->tran_id ?? null,
                    'registration_id' => $regId, // Added for QR generation
                ];
            }

            // Also add any transactions that are FAILED or REJECTED (not already represented by periods)
            foreach ($transactions as $tx) {
                // If this transaction is already linked to a period in our ledger, we might skip or show as detail
                // For now, let's just add failed ones as separate entries to inform the student
                if (in_array(strtoupper($tx->status), ['FAILED', 'REJECTED', 'REJECTED_FULL'])) {
                    $ledger[] = [
                        'id' => 'TXN-' . $tx->id,
                        'amount' => (float)($tx->amount ?? 0),
                        'status' => strtoupper($tx->status),
                        'payment_status' => $tx->status,
                        'method' => 'ABA Pay',
                        'description' => "Attempted: " . ($tx->pay_plan_type === 'YEAR' ? 'Full Year' : "Sem {$tx->semester}"),
                        'date' => $tx->created_at ?? $tx->updated_at,
                        'type' => 'TRANSACTION',
                        'tran_id' => $tx->tran_id
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $ledger
            ]);

        } catch (\Throwable $e) {
            Log::error('getStudentPayments error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch payment data'
            ], 500);
        }
    }
}
