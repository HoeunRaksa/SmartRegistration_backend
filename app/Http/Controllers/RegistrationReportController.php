<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

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
            'sample_department' => DB::table('departments')->limit(1)->first(),
            'sample_major' => DB::table('majors')->limit(1)->first(),
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

    private function tableColumns(string $table): array
    {
        try {
            return DB::getSchemaBuilder()->getColumnListing($table);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function hasColumn(string $table, string $col): bool
    {
        try {
            return DB::getSchemaBuilder()->hasColumn($table, $col);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Detect relation between registrations <-> students:
     * A) students.registration_id = registrations.id  (your flow)
     * B) registrations.student_id = students.id       (fallback)
     */
    private function joinStudent(&$q): array
    {
        $regHasStudentId = $this->hasColumn('registrations', 'student_id');
        $studentHasRegistrationId = $this->hasColumn('students', 'registration_id');

        if ($studentHasRegistrationId) {
            $q->leftJoin('students as s', 's.registration_id', '=', 'r.id');
            return ['mode' => 'students.registration_id', 'student_id_expr' => 's.id'];
        }

        if ($regHasStudentId) {
            $q->leftJoin('students as s', 's.id', '=', 'r.student_id');
            return ['mode' => 'registrations.student_id', 'student_id_expr' => 'r.student_id'];
        }

        $q->leftJoin('students as s', DB::raw('1'), '=', DB::raw('0'));
        return ['mode' => 'none', 'student_id_expr' => 's.id'];
    }

    /**
     * Build base query:
     * - joins department, major, student
     * - joins latest/exact student_academic_periods
     */
    private function baseReportQuery(Request $request)
    {
        $semester = (int)($request->input('semester', 0));
        $academicYear = $request->input('academic_year', null);

        $q = DB::table('registrations as r')
            ->leftJoin('departments as d', 'd.id', '=', 'r.department_id')
            ->leftJoin('majors as m', 'm.id', '=', 'r.major_id');

        $joinInfo = $this->joinStudent($q);
        $studentIdExpr = $joinInfo['student_id_expr']; // "s.id" OR "r.student_id"

        // ===== Join periods =====
        if ($semester === 1 || $semester === 2) {
            $q->leftJoin('student_academic_periods as sap', function ($join) use ($semester, $academicYear, $studentIdExpr) {
                $join->on('sap.student_id', '=', DB::raw($studentIdExpr))
                    ->where('sap.semester', '=', $semester);

                if (!empty($academicYear)) {
                    $join->where('sap.academic_year', '=', $academicYear);
                }
            });
        } else {
            $sub = DB::table('student_academic_periods as p')
                ->selectRaw('p.student_id, MAX(p.id) as max_id')
                ->groupBy('p.student_id');

            if (!empty($academicYear)) {
                $sub->where('p.academic_year', $academicYear);
            }

            $q->leftJoinSub($sub, 'latest_p', function ($join) use ($studentIdExpr) {
                $join->on('latest_p.student_id', '=', DB::raw($studentIdExpr));
            });

            $q->leftJoin('student_academic_periods as sap', function ($join) {
                $join->on('sap.id', '=', 'latest_p.max_id');
            });
        }

        // ✅ IMPORTANT FIX: department_name might be "name"
        $deptNameSql = $this->hasColumn('departments', 'department_name')
            ? 'd.department_name'
            : ($this->hasColumn('departments', 'name') ? 'd.name' : 'NULL');

        $majorNameSql = $this->hasColumn('majors', 'major_name')
            ? 'm.major_name'
            : ($this->hasColumn('majors', 'name') ? 'm.name' : 'NULL');

        // ===== Select =====
        $q->select([
            'r.*',
            DB::raw("$deptNameSql as department_name"),
            DB::raw("$majorNameSql as major_name"),
            DB::raw('s.student_code as student_code'),

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

        if ($request->filled('payment_status')) {
            $target = strtoupper(trim($request->payment_status));
            $q->whereRaw('UPPER(TRIM(COALESCE(sap.payment_status, r.payment_status, "PENDING"))) = ?', [$target]);
        }

        if ($request->filled('academic_year')) {
            $ay = $request->academic_year;
            $regHasAy = $this->hasColumn('registrations', 'academic_year');

            $q->where(function ($w) use ($ay, $regHasAy) {
                $w->where('sap.academic_year', $ay);

                if ($regHasAy) {
                    $w->orWhere(function ($w2) use ($ay) {
                        $w2->whereNull('sap.academic_year')
                            ->where('r.academic_year', $ay);
                    });
                }
            });
        }

        if ($semester === 1 || $semester === 2) {
            $regHasSem = $this->hasColumn('registrations', 'semester');

            $q->where(function ($w) use ($semester, $regHasSem) {
                $w->where('sap.semester', $semester);

                if ($regHasSem) {
                    $w->orWhere(function ($w2) use ($semester) {
                        $w2->whereNull('sap.semester')
                            ->where('r.semester', $semester);
                    });
                }
            });
        }

        return $q;
    }

    /* ================== GENERATE (JSON) ================== */

    public function generate(Request $request)
    {
        try {
            $semester = (int)($request->input('semester', 0));
            $rows = $this->baseReportQuery($request)->orderBy('r.created_at', 'desc')->get();

            $transformed = $rows->map(function ($reg) use ($semester) {
                $status = $reg->period_payment_status ?? $reg->payment_status ?? 'PENDING';
                $amount = $reg->period_tuition_amount ?? $reg->payment_amount ?? 0;
                $paidAt  = $reg->period_paid_at ?? $reg->payment_date ?? null;

                $reportSemester = $reg->period_semester
                    ?? ($this->hasColumn('registrations', 'semester') ? ($reg->semester ?? ($semester ?: null)) : ($semester ?: null));

                $reportAcademicYear = $reg->period_academic_year
                    ?? ($this->hasColumn('registrations', 'academic_year') ? ($reg->academic_year ?? null) : null);

                return [
                    'id' => $reg->id,
                    'first_name' => $reg->first_name ?? null,
                    'last_name' => $reg->last_name ?? null,
                    'full_name_en' => $reg->full_name_en ?? null,
                    'full_name_kh' => $reg->full_name_kh ?? null,

                    'gender' => $reg->gender ?? null,

                    'payment_status' => $status ?? 'PENDING',
                    'payment_amount' => (float)($amount ?? 0),
                    'payment_date' => $paidAt,

                    'semester' => $reportSemester,
                    'academic_year' => $reportAcademicYear,

                    'shift' => $reg->shift ?? null,
                    'created_at' => $reg->created_at ? date('Y-m-d H:i:s', strtotime($reg->created_at)) : null,

                    'department_name' => $reg->department_name ?? null,
                    'major_name' => $reg->major_name ?? null,
                    'student_code' => $reg->student_code ?? null,

                    'period_payment_status' => $reg->period_payment_status ?? null,
                    'period_paid_at' => $reg->period_paid_at ?? null,
                    'period_tuition_amount' => $reg->period_tuition_amount ?? null,
                    'period_semester' => $reg->period_semester ?? null,
                    'period_academic_year' => $reg->period_academic_year ?? null,
                ];
            });

            $total = $transformed->count();
            $male = $transformed->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count();
            $female = $transformed->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count();

            $pending = 0;
            $completed = 0;
            $totalAmount = 0.0;
            $paidAmount = 0.0;

            foreach ($transformed as $r) {
                $statusU = $this->cleanUpper($r['payment_status'] ?? 'PENDING');
                $amt = (float)($r['payment_amount'] ?? 0);

                $totalAmount += $amt;

                if ($this->isPaid($statusU)) {
                    $completed++;
                    $paidAmount += $amt;
                } else {
                    $pending++;
                }
            }

            $stats = [
                'total_registrations' => $total,
                'total_male' => $male,
                'total_female' => $female,
                'payment_pending' => $pending,
                'payment_completed' => $completed,
                'total_amount' => (float)$totalAmount,
                'paid_amount' => (float)$paidAmount,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'registrations' => $transformed,
                    'statistics' => $stats,
                    'filters' => $request->all(),
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Registration report generate failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error (generate)',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* ================== EXPORT PDF ================== */

    public function exportPdf(Request $request)
    {
        try {
            $semester = (int)($request->input('semester', 0));
            $rows = $this->baseReportQuery($request)->orderBy('r.created_at', 'desc')->get();

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
                'semester' => $semester,
                'generated_date' => now()->format('F d, Y H:i:s'),
            ];

            $pdf = Pdf::loadView('reports.registration', $data)->setPaper('a4', 'landscape');

            $filename = 'registration_report_' . now()->format('YmdHis') . '.pdf';
            return $pdf->download($filename);
        } catch (\Throwable $e) {
            \Log::error('Registration report pdf failed', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error (pdf)',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /* ================== SUMMARY ================== */

    public function summary(Request $request)
    {
        try {
            $rows = $this->baseReportQuery($request)->get();

            $pending = 0;
            $completed = 0;
            $failed = 0;
            $paidAmount = 0.0;
            $pendingAmount = 0.0;

            foreach ($rows as $r) {
                $statusU = $this->cleanUpper($r->period_payment_status ?? $r->payment_status ?? 'PENDING');
                $amt = (float)($r->period_tuition_amount ?? $r->payment_amount ?? 0);

                if ($this->isPaid($statusU)) {
                    $completed++;
                    $paidAmount += $amt;
                } elseif ($statusU === 'FAILED') {
                    $failed++;
                    $pendingAmount += $amt;
                } else {
                    $pending++;
                    $pendingAmount += $amt;
                }
            }

            $summary = [
                'total_registrations' => $rows->count(),
                'by_gender' => [
                    'male' => $rows->filter(fn($r) => strtolower(trim((string)($r->gender ?? ''))) === 'male')->count(),
                    'female' => $rows->filter(fn($r) => strtolower(trim((string)($r->gender ?? ''))) === 'female')->count(),
                ],
                'by_payment_status' => [
                    'pending' => $pending,
                    'completed' => $completed,
                    'failed' => $failed,
                ],
                'financial' => [
                    'paid_amount' => (float)$paidAmount,
                    'pending_amount' => (float)$pendingAmount,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Registration report summary failed', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Internal Server Error (summary)',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
