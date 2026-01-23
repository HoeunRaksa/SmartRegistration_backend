<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;

class RegistrationReportController extends Controller
{
    /* ================== TEST ================== */
    public function test()
    {
        return response()->json([
            'success' => true,
            'counts' => [
                'registrations' => DB::table('registrations')->count(),
                'students' => DB::table('students')->count(),
                'departments' => DB::table('departments')->count(),
                'majors' => DB::table('majors')->count(),
                'student_academic_periods' => DB::table('student_academic_periods')->count(),
            ],
            'sample_registration' => DB::table('registrations')->limit(1)->first(),
            'sample_student' => DB::table('students')->limit(1)->first(),
        ]);
    }

    /* ================== HELPERS ================== */

    private function cleanUpper($v, $fallback = 'PENDING')
    {
        $s = strtoupper(trim((string)($v ?? '')));
        return $s !== '' ? $s : $fallback;
    }

    private function isPaid($statusUpper)
    {
        return in_array($statusUpper, ['PAID', 'COMPLETED', 'SUCCESS', 'APPROVED', 'DONE'], true);
    }

    /**
     * ✅ Base query (NEW FLOW FIRST):
     * registrations r
     * students s (join by s.registration_id = r.id)  ✅ FIX
     * student_academic_periods sap (join by sap.student_id = s.id)
     *
     * Supports:
     * - semester selected: join exact sap row for that semester (+ academic_year if provided)
     * - semester not selected: join latest sap row per student (MySQL 5.7 friendly)
     *
     * Also selects:
     * - department_name = departments.name ✅ you want name not department_name
     */
    private function baseReportQuery(Request $request)
    {
        $semester = (int)($request->input('semester', 0)); // 0 = all
        $academicYear = $request->input('academic_year', null);

        $q = DB::table('registrations as r')
            ->leftJoin('departments as d', 'd.id', '=', 'r.department_id')
            ->leftJoin('majors as m', 'm.id', '=', 'r.major_id')

            // ✅ FIX: registrations has no student_id. Student table has registration_id.
            ->leftJoin('students as s', 's.registration_id', '=', 'r.id');

        // ===== Join student_academic_periods as sap =====
        if ($semester === 1 || $semester === 2) {
            // exact semester row (+ academic year if provided)
            $q->leftJoin('student_academic_periods as sap', function ($join) use ($semester, $academicYear) {
                $join->on('sap.student_id', '=', 's.id')
                    ->where('sap.semester', '=', $semester);

                if (!empty($academicYear)) {
                    $join->where('sap.academic_year', '=', $academicYear);
                }
            });
        } else {
            // latest row per student (optionally within academic year)
            $sub = DB::table('student_academic_periods as p')
                ->selectRaw('p.student_id, MAX(p.id) as max_id')
                ->groupBy('p.student_id');

            if (!empty($academicYear)) {
                $sub->where('p.academic_year', $academicYear);
            }

            $q->leftJoinSub($sub, 'latest_p', function ($join) {
                $join->on('latest_p.student_id', '=', 's.id');
            });

            $q->leftJoin('student_academic_periods as sap', function ($join) {
                $join->on('sap.id', '=', 'latest_p.max_id');
            });
        }

        // ===== Select =====
        $q->select([
            'r.*',

            // ✅ you want name
            DB::raw('d.name as department_name'),

            // majors table uses major_name in your code
            DB::raw('m.major_name as major_name'),

            // student info
            DB::raw('s.student_code as student_code'),

            // period fields
            DB::raw('sap.payment_status as period_payment_status'),
            DB::raw('sap.paid_at as period_paid_at'),
            DB::raw('sap.tuition_amount as period_tuition_amount'),
            DB::raw('sap.semester as period_semester'),
            DB::raw('sap.academic_year as period_academic_year'),
        ]);

        // ===== Filters =====
        if ($request->filled('department_id')) $q->where('r.department_id', $request->department_id);
        if ($request->filled('major_id')) $q->where('r.major_id', $request->major_id);
        if ($request->filled('shift')) $q->where('r.shift', $request->shift);
        if ($request->filled('gender')) $q->where('r.gender', $request->gender);

        if ($request->filled('date_from')) $q->whereDate('r.created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $q->whereDate('r.created_at', '<=', $request->date_to);

        // payment_status filter (period-first fallback old)
        if ($request->filled('payment_status')) {
            $target = strtoupper(trim($request->payment_status));
            $q->whereRaw('UPPER(TRIM(COALESCE(sap.payment_status, r.payment_status, "PENDING"))) = ?', [$target]);
        }

        // academic_year filter (period-first fallback old)
        if ($request->filled('academic_year')) {
            $ay = $request->academic_year;
            $q->where(function ($w) use ($ay) {
                $w->where('sap.academic_year', $ay)
                    ->orWhere(function ($w2) use ($ay) {
                        $w2->whereNull('sap.academic_year')
                            ->where('r.academic_year', $ay);
                    });
            });
        }

        // semester filter (only if explicitly selected)
        if ($semester === 1 || $semester === 2) {
            $q->where(function ($w) use ($semester) {
                $w->where('sap.semester', $semester)
                    ->orWhere(function ($w2) use ($semester) {
                        $w2->whereNull('sap.semester')
                            ->where('r.semester', $semester);
                    });
            });
        }

        return $q;
    }

    /* ================== GENERATE (JSON) ================== */

    public function generate(Request $request)
    {
        $semester = (int)($request->input('semester', 0));

        $rows = $this->baseReportQuery($request)
            ->orderBy('r.created_at', 'desc')
            ->get();

        $transformed = $rows->map(function ($reg) use ($semester) {
            $status = $reg->period_payment_status ?? $reg->payment_status ?? 'PENDING';
            $amount = $reg->period_tuition_amount ?? $reg->payment_amount ?? 0;
            $paidAt = $reg->period_paid_at ?? $reg->payment_date ?? null;

            $reportSemester = $reg->period_semester ?? ($reg->semester ?? ($semester ?: null));
            $reportAcademicYear = $reg->period_academic_year ?? ($reg->academic_year ?? null);

            return [
                'id' => $reg->id,
                'first_name' => $reg->first_name ?? null,
                'last_name' => $reg->last_name ?? null,
                'full_name_en' => $reg->full_name_en ?? ($reg->full_name ?? null),
                'full_name_kh' => $reg->full_name_kh ?? null,
                'gender' => $reg->gender ?? null,

                // ✅ effective payment info
                'payment_status' => $status ?? 'PENDING',
                'payment_amount' => (float)($amount ?? 0),
                'payment_date' => $paidAt,

                'semester' => $reportSemester,
                'academic_year' => $reportAcademicYear,

                'shift' => $reg->shift ?? null,
                'created_at' => $reg->created_at ? date('Y-m-d H:i:s', strtotime($reg->created_at)) : null,

                // ✅ flat names for frontend
                'department_name' => $reg->department_name ?? null,
                'major_name' => $reg->major_name ?? null,
                'student_code' => $reg->student_code ?? null,

                // ✅ keep period fields too
                'period_payment_status' => $reg->period_payment_status ?? null,
                'period_paid_at' => $reg->period_paid_at ?? null,
                'period_tuition_amount' => $reg->period_tuition_amount ?? null,
                'period_semester' => $reg->period_semester ?? null,
                'period_academic_year' => $reg->period_academic_year ?? null,
            ];
        });

        // Stats
        $total = $transformed->count();
        $male = $transformed->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count();
        $female = $transformed->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count();

        $pendingCount = 0;
        $completedCount = 0;
        $totalAmount = 0.0;
        $paidAmount = 0.0;

        foreach ($transformed as $r) {
            $statusU = $this->cleanUpper($r['payment_status'] ?? 'PENDING');
            $amt = (float)($r['payment_amount'] ?? 0);
            $totalAmount += $amt;

            if ($this->isPaid($statusU)) {
                $completedCount++;
                $paidAmount += $amt;
            } else {
                $pendingCount++;
            }
        }

        $stats = [
            'total_registrations' => $total,
            'total_male' => $male,
            'total_female' => $female,
            'payment_pending' => $pendingCount,
            'payment_completed' => $completedCount,
            'total_amount' => (float)$totalAmount,
            'paid_amount' => (float)$paidAmount,
        ];

        $by_department = $transformed
            ->filter(fn($r) => !empty($r['department_name']))
            ->groupBy('department_name')
            ->map(fn($items) => [
                'count' => $items->count(),
                'male' => $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count(),
                'female' => $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count(),
            ]);

        $by_major = $transformed
            ->filter(fn($r) => !empty($r['major_name']))
            ->groupBy('major_name')
            ->map(fn($items) => [
                'count' => $items->count(),
                'male' => $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count(),
                'female' => $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'registrations' => $transformed,
                'statistics' => $stats,
                'by_department' => $by_department,
                'by_major' => $by_major,
                'filters' => $request->all(),
            ],
        ]);
    }

    /* ================== EXPORT PDF ================== */

    public function exportPdf(Request $request)
    {
        $rows = $this->baseReportQuery($request)
            ->orderBy('r.created_at', 'desc')
            ->get();

        $totalAmount = 0.0;
        $paidAmount = 0.0;
        $pendingCount = 0;
        $completedCount = 0;

        foreach ($rows as $r) {
            $statusU = $this->cleanUpper($r->period_payment_status ?? $r->payment_status ?? 'PENDING');
            $amt = (float)($r->period_tuition_amount ?? $r->payment_amount ?? 0);

            $totalAmount += $amt;

            if ($this->isPaid($statusU)) {
                $completedCount++;
                $paidAmount += $amt;
            } else {
                $pendingCount++;
            }
        }

        $stats = [
            'total_registrations' => $rows->count(),
            'total_male' => $rows->filter(fn($r) => strtolower(trim((string)($r->gender ?? ''))) === 'male')->count(),
            'total_female' => $rows->filter(fn($r) => strtolower(trim((string)($r->gender ?? ''))) === 'female')->count(),
            'payment_pending' => $pendingCount,
            'payment_completed' => $completedCount,
            'total_amount' => (float)$totalAmount,
            'paid_amount' => (float)$paidAmount,
        ];

        $data = [
            'registrations' => $rows,
            'stats' => $stats,
            'filters' => $request->all(),
            'generated_date' => now()->format('F d, Y H:i:s'),
        ];

        $pdf = Pdf::loadView('reports.registration', $data)->setPaper('a4', 'landscape');
        return $pdf->download('registration_report_' . now()->format('YmdHis') . '.pdf');
    }

    /* ================== SUMMARY ================== */

    public function summary(Request $request)
    {
        try {
            // ✅ Use same logic as report (period-first)
            $rows = $this->baseReportQuery($request)->select([
                'r.id',
                'r.gender',
                'r.payment_status',
                'r.payment_amount',
                DB::raw('sap.payment_status as period_payment_status'),
                DB::raw('sap.tuition_amount as period_tuition_amount'),
            ])->get();

            $normalize = fn($s) => strtoupper(trim((string)($s ?? 'PENDING')));
            $paidStatuses = ['PAID','COMPLETED','SUCCESS','APPROVED','DONE'];

            $paidAmount = 0.0;
            $pendingAmount = 0.0;

            $pendingCount = 0;
            $completedCount = 0;
            $failedCount = 0;

            foreach ($rows as $r) {
                $status = $normalize($r->period_payment_status ?? $r->payment_status ?? 'PENDING');
                $amount = (float)($r->period_tuition_amount ?? $r->payment_amount ?? 0);

                if (in_array($status, $paidStatuses, true)) {
                    $completedCount++;
                    $paidAmount += $amount;
                } elseif ($status === 'FAILED') {
                    $failedCount++;
                    $pendingAmount += $amount;
                } else {
                    $pendingCount++;
                    $pendingAmount += $amount;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_registrations' => $rows->count(),
                    'by_gender' => [
                        'male' => $rows->filter(fn($x) => strtolower(trim((string)$x->gender)) === 'male')->count(),
                        'female' => $rows->filter(fn($x) => strtolower(trim((string)$x->gender)) === 'female')->count(),
                    ],
                    'by_payment_status' => [
                        'pending' => $pendingCount,
                        'completed' => $completedCount,
                        'failed' => $failedCount,
                    ],
                    'financial' => [
                        'paid_amount' => (float)$paidAmount,
                        'pending_amount' => (float)$pendingAmount,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Registration report summary failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Summary failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
