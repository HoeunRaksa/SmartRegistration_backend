<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Registration; 

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
            'sample' => DB::table('registrations')->limit(1)->first(),
        ]);
    }

    /* ================== CORE HELPERS ================== */

    private function cleanUpper($v, $fallback = 'PENDING')
    {
        $s = strtoupper(trim((string)($v ?? '')));
        return $s !== '' ? $s : $fallback;
    }

    private function isPaid($statusUpper)
    {
        return in_array($statusUpper, ['PAID','COMPLETED','SUCCESS','APPROVED','DONE'], true);
    }

    private function isPending($statusUpper)
    {
        return in_array($statusUpper, ['PENDING','UNPAID','NEW','INIT','PROCESSING',''], true);
    }

    /**
     * Build a query that returns registrations with:
     * - department_name, major_name, student_code
     * - period_* fields from student_academic_periods (NEW FLOW)
     *
     * Key logic:
     * - If semester is provided (1/2): join EXACT row for that semester (+ academic_year if provided)
     * - If semester not provided: join "latest period row per student" (MySQL 5.7 compatible)
     */
    private function baseReportQuery(Request $request)
    {
        $semester = (int)($request->input('semester', 0)); // 0 = all
        $academicYear = $request->input('academic_year', null);

        $q = DB::table('registrations as r')
            ->leftJoin('departments as d', 'd.id', '=', 'r.department_id')
            ->leftJoin('majors as m', 'm.id', '=', 'r.major_id')
            ->leftJoin('students as s', 's.id', '=', 'r.student_id');

        // ===== Join student_academic_periods as sap (NEW FLOW) =====
        if ($semester === 1 || $semester === 2) {
            // exact semester row
            $q->leftJoin('student_academic_periods as sap', function ($join) use ($semester, $academicYear) {
                $join->on('sap.student_id', '=', 'r.student_id')
                    ->where('sap.semester', '=', $semester);

                if (!empty($academicYear)) {
                    $join->where('sap.academic_year', '=', $academicYear);
                }
            });
        } else {
            // no semester selected -> take latest period row per student (MySQL 5.7 compatible)
            // If academic_year provided, take latest within that year.
            $sub = DB::table('student_academic_periods as p')
                ->selectRaw('MAX(p.id) as max_id')
                ->groupBy('p.student_id');

            if (!empty($academicYear)) {
                $sub->where('p.academic_year', $academicYear)
                    ->selectRaw('p.student_id'); // needed for groupBy
            } else {
                $sub->selectRaw('p.student_id');
            }

            $q->leftJoinSub($sub, 'latest_p', function ($join) {
                $join->on('latest_p.student_id', '=', 'r.student_id');
            });

            $q->leftJoin('student_academic_periods as sap', function ($join) {
                $join->on('sap.id', '=', 'latest_p.max_id');
            });
        }

        // ===== Select =====
        $q->select([
            'r.*',

            // flat fields for frontend + blade
            DB::raw('d.department_name as department_name'),
            DB::raw('m.major_name as major_name'),
            DB::raw('s.student_code as student_code'),

            // period fields (what your frontend expects)
            DB::raw('sap.payment_status as period_payment_status'),
            DB::raw('sap.paid_at as period_paid_at'),
            DB::raw('sap.tuition_amount as period_tuition_amount'),
            DB::raw('sap.semester as period_semester'),
            DB::raw('sap.academic_year as period_academic_year'),
        ]);

        // ===== Filters (keep same behavior) =====
        if ($request->filled('department_id')) {
            $q->where('r.department_id', $request->department_id);
        }

        if ($request->filled('major_id')) {
            $q->where('r.major_id', $request->major_id);
        }

        if ($request->filled('shift')) {
            $q->where('r.shift', $request->shift);
        }

        if ($request->filled('gender')) {
            $q->where('r.gender', $request->gender);
        }

        if ($request->filled('date_from')) {
            $q->whereDate('r.created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $q->whereDate('r.created_at', '<=', $request->date_to);
        }

        /**
         * ✅ payment_status filter MUST check period first (new flow),
         * then fallback old registrations.payment_status if period is null.
         */
        if ($request->filled('payment_status')) {
            $target = strtoupper(trim($request->payment_status));
            $q->whereRaw('UPPER(TRIM(COALESCE(sap.payment_status, r.payment_status, "PENDING"))) = ?', [$target]);
        }

        /**
         * ✅ academic_year filter must apply to period academic_year first,
         * but if semester not selected and no period row exists, we still allow fallback to r.academic_year.
         * This keeps old data support.
         */
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

        // semester filter: only if explicitly provided (1/2)
        if ($semester === 1 || $semester === 2) {
            // we already joined exact semester row; but keep safe fallback for old data
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

        $query = $this->baseReportQuery($request);

        $rows = $query->orderBy('r.created_at', 'desc')->get();

        // Transform for frontend (keep your structure)
        $transformed = $rows->map(function ($reg) use ($semester) {
            $status = $reg->period_payment_status ?? $reg->payment_status ?? 'PENDING';
            $amount = $reg->period_tuition_amount ?? $reg->payment_amount ?? 0;
            $paidAt = $reg->period_paid_at ?? $reg->payment_date ?? null;

            // semester/year shown: period first, else fallback
            $reportSemester = $reg->period_semester ?? ($reg->semester ?? ($semester ?: null));
            $reportAcademicYear = $reg->period_academic_year ?? ($reg->academic_year ?? null);

            return [
                'id' => $reg->id,

                'first_name' => $reg->first_name ?? null,
                'last_name' => $reg->last_name ?? null,
                'full_name_en' => $reg->full_name_en ?? null,
                'full_name_kh' => $reg->full_name_kh ?? null,

                'gender' => $reg->gender ?? null,
                'date_of_birth' => $reg->date_of_birth ?? null,
                'personal_email' => $reg->personal_email ?? null,
                'phone_number' => $reg->phone_number ?? null,
                'address' => $reg->address ?? null,

                // ✅ effective payment info for report
                'payment_status' => $status ?? 'PENDING',
                'payment_amount' => (float)($amount ?? 0),
                'payment_date' => $paidAt,

                // ✅ semester-aware fields
                'semester' => $reportSemester,
                'academic_year' => $reportAcademicYear,

                'shift' => $reg->shift ?? null,
                'batch' => $reg->batch ?? null,
                'faculty' => $reg->faculty ?? null,
                'created_at' => $reg->created_at ? date('Y-m-d H:i:s', strtotime($reg->created_at)) : null,

                // flat names (frontend uses these)
                'department_name' => $reg->department_name ?? null,
                'major_name' => $reg->major_name ?? null,
                'student_code' => $reg->student_code ?? null,

                // ✅ period fields (frontend helpers use these first)
                'period_payment_status' => $reg->period_payment_status ?? null,
                'period_paid_at' => $reg->period_paid_at ?? null,
                'period_tuition_amount' => $reg->period_tuition_amount ?? null,
                'period_semester' => $reg->period_semester ?? null,
                'period_academic_year' => $reg->period_academic_year ?? null,
            ];
        });

        // Stats computed from transformed (so UI matches)
        $total = $transformed->count();
        $male = $transformed->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count();
        $female = $transformed->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count();

        $paymentPending = 0;
        $paymentCompleted = 0;
        $totalAmount = 0.0;
        $paidAmount = 0.0;

        foreach ($transformed as $r) {
            $statusU = $this->cleanUpper($r['payment_status'] ?? 'PENDING');
            $amt = (float)($r['payment_amount'] ?? 0);

            $totalAmount += $amt;

            if ($this->isPaid($statusU)) {
                $paymentCompleted++;
                $paidAmount += $amt;
            } else {
                $paymentPending++;
            }
        }

        $stats = [
            'total_registrations' => $total,
            'total_male' => $male,
            'total_female' => $female,
            'payment_pending' => $paymentPending,
            'payment_completed' => $paymentCompleted,
            'total_amount' => (float)$totalAmount,
            'paid_amount' => (float)$paidAmount,
        ];

        // by_department / by_major (use transformed flat names)
        $by_department = $transformed
            ->filter(fn($r) => !empty($r['department_name']))
            ->groupBy('department_name')
            ->map(function ($items) {
                $male = $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count();
                $female = $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count();
                return [
                    'count' => $items->count(),
                    'male' => $male,
                    'female' => $female,
                ];
            });

        $by_major = $transformed
            ->filter(fn($r) => !empty($r['major_name']))
            ->groupBy('major_name')
            ->map(function ($items) {
                $male = $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'male')->count();
                $female = $items->filter(fn($r) => strtolower(trim((string)($r['gender'] ?? ''))) === 'female')->count();
                return [
                    'count' => $items->count(),
                    'male' => $male,
                    'female' => $female,
                ];
            });

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
        $semester = (int)($request->input('semester', 0));

        // IMPORTANT: Use same query as generate()
        $rows = $this->baseReportQuery($request)
            ->orderBy('r.created_at', 'desc')
            ->get();

        // Build stats from effective (period-first) fields for PDF
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
            'registrations' => $rows, // blade uses $reg->period_* and fallback; we provide them
            'stats' => $stats,
            'filters' => $request->all(),
            'semester' => $semester,
            'generated_date' => now()->format('F d, Y H:i:s'),
        ];

        $pdf = Pdf::loadView('reports.registration', $data)->setPaper('a4', 'landscape');

        $filename = 'registration_report_' . now()->format('YmdHis') . '.pdf';
        return $pdf->download($filename);
    }

    /* ================== SUMMARY ================== */
public function summary(Request $request)
{
    try {
        $semester = (int) $request->input('semester', 0);
        $academicYear = $request->input('academic_year', '');

        // Decide which table exists in your DB (new flow first)
        $periodTable = null;
        if (Schema::hasTable('student_academic_periods')) {
            $periodTable = 'student_academic_periods';
        } elseif (Schema::hasTable('registration_periods')) {
            $periodTable = 'registration_periods';
        }

        // Base query
        $query = Registration::query()->from('registrations');

        // If we have a period table and semester is selected, join it
        if ($periodTable && ($semester === 1 || $semester === 2)) {

            // Column names in join: try common patterns
            // New flow usually: registration_id OR student_id
            // We'll handle both by checking columns
            $joinOn = null;

            // Prefer registration_id if exists
            if (Schema::hasColumn($periodTable, 'registration_id')) {
                $joinOn = [$periodTable . '.registration_id', '=', 'registrations.id'];
            } elseif (Schema::hasColumn($periodTable, 'student_id')) {
                // fallback: join via students table if period table uses student_id
                // But registrations may have student_id column already (depends on your schema)
                if (Schema::hasColumn('registrations', 'student_id')) {
                    $joinOn = [$periodTable . '.student_id', '=', 'registrations.student_id'];
                } else {
                    // cannot join reliably -> do not join
                    $joinOn = null;
                }
            }

            if ($joinOn) {
                $query->leftJoin($periodTable . ' as rp', function ($join) use ($joinOn, $semester, $academicYear) {
                    $join->on($joinOn[0], $joinOn[1], $joinOn[2]);

                    // semester column name could be: semester or period_semester
                    if (Schema::hasColumn(str_replace(' as rp', '', 'rp'), 'semester')) {
                        $join->where('rp.semester', '=', $semester);
                    } else {
                        // if your table uses different column name, adjust here
                        $join->where('rp.semester', '=', $semester);
                    }

                    if (!empty($academicYear) && Schema::hasColumn(str_replace(' as rp', '', 'rp'), 'academic_year')) {
                        $join->where('rp.academic_year', '=', $academicYear);
                    }
                });

                // Select needed fields for summary
                $query->select([
                    'registrations.id',
                    'registrations.gender',
                    'registrations.payment_status',
                    'registrations.payment_amount',
                    DB::raw('rp.payment_status as period_payment_status'),
                    DB::raw('rp.tuition_amount as period_tuition_amount'),
                ]);
            } else {
                // join not possible -> fallback to registrations only
                $query->select([
                    'registrations.id',
                    'registrations.gender',
                    'registrations.payment_status',
                    'registrations.payment_amount',
                ]);
            }
        } else {
            // No join (all semesters) - filter by registrations academic_year if requested
            if (!empty($academicYear) && Schema::hasColumn('registrations', 'academic_year')) {
                $query->where('registrations.academic_year', $academicYear);
            }

            $query->select([
                'registrations.id',
                'registrations.gender',
                'registrations.payment_status',
                'registrations.payment_amount',
            ]);
        }

        $rows = $query->get();

        // Helper for status + amount
        $normalize = function ($s) {
            return strtoupper(trim((string)($s ?? 'PENDING')));
        };

        $paidStatuses = ['PAID', 'COMPLETED', 'SUCCESS', 'APPROVED', 'DONE'];

        $paidAmount = 0.0;
        $pendingAmount = 0.0;

        $pendingCount = 0;
        $completedCount = 0;
        $failedCount = 0;

        foreach ($rows as $r) {
            $status = $normalize($r->period_payment_status ?? $r->payment_status ?? 'PENDING');
            $amount = (float) ($r->period_tuition_amount ?? $r->payment_amount ?? 0);

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

        $summary = [
            'total_registrations' => $rows->count(),
            'by_gender' => [
                'male' => $rows->where('gender', 'Male')->count(),
                'female' => $rows->where('gender', 'Female')->count(),
            ],
            'by_payment_status' => [
                'pending' => $pendingCount,
                'completed' => $completedCount,
                'failed' => $failedCount,
            ],
            'financial' => [
                'paid_amount' => (float) $paidAmount,
                'pending_amount' => (float) $pendingAmount,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary,
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
            'error' => $e->getMessage(), // keep for now, remove later in production
        ], 500);
    }
}


}
