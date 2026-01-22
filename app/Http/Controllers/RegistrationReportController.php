<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\Student;
use App\Models\Department;
use App\Models\Major;
use Illuminate\Support\Facades\DB;

class RegistrationReportController extends Controller
{
    /**
     * TEST METHOD - Check if data exists
     * Add this temporarily to debug
     */
    public function test()
    {
        $totalRegistrations = Registration::count();
        $totalStudents = Student::count();
        $totalDepartments = Department::count();
        $totalMajors = Major::count();

        $sampleRegistration = Registration::with(['department', 'major', 'student'])->first();

        return response()->json([
            'success' => true,
            'counts' => [
                'registrations' => $totalRegistrations,
                'students' => $totalStudents,
                'departments' => $totalDepartments,
                'majors' => $totalMajors,
            ],
            'sample_registration' => $sampleRegistration,
            'all_registrations_raw' => Registration::limit(5)->get(),
        ]);
    }

    /**
     * Generate registration report based on filters
     */
    public function generate(Request $request)
    {
        // ✅ semester comes from frontend if you add it (optional)
        $semester = (int)($request->input('semester', 0)); // 0 = all/none

        // Start with registrations
        $query = Registration::query()->with(['department', 'major', 'student']);

        /**
         * ✅ IMPORTANT:
         * Join per-semester payment info so report shows correct status for a specific semester.
         * Table below assumed: registration_periods
         * Columns assumed: registration_id, semester, academic_year, payment_status, paid_at, tuition_amount
         *
         * If your actual table/columns differ, adjust here only (endpoints unchanged).
         */
        if ($semester === 1 || $semester === 2) {
            $query->leftJoin('registration_periods as rp', function ($join) use ($semester) {
                $join->on('rp.registration_id', '=', 'registrations.id')
                     ->where('rp.semester', '=', $semester);
            });

            // Select registrations.* plus period fields
            $query->select([
                'registrations.*',
                DB::raw('rp.payment_status as period_payment_status'),
                DB::raw('rp.paid_at as period_paid_at'),
                DB::raw('rp.tuition_amount as period_tuition_amount'),
                DB::raw('rp.semester as period_semester'),
                DB::raw('rp.academic_year as period_academic_year'),
            ]);
        }

        // Apply filters ONLY if they have values
        if ($request->filled('department_id')) {
            $query->where('registrations.department_id', $request->department_id);
        }

        if ($request->filled('major_id')) {
            $query->where('registrations.major_id', $request->major_id);
        }

        // ✅ payment_status filter should use semester-period status when semester selected
        if ($request->filled('payment_status')) {
            if ($semester === 1 || $semester === 2) {
                $query->where('rp.payment_status', $request->payment_status);
            } else {
                $query->where('registrations.payment_status', $request->payment_status);
            }
        }

        // academic year filter: if semester selected -> period academic year, else registrations academic_year
        if ($request->filled('academic_year')) {
            if ($semester === 1 || $semester === 2) {
                $query->where('rp.academic_year', $request->academic_year);
            } else {
                $query->where('registrations.academic_year', $request->academic_year);
            }
        }

        if ($request->filled('shift')) {
            $query->where('registrations.shift', $request->shift);
        }

        if ($request->filled('gender')) {
            $query->where('registrations.gender', $request->gender);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('registrations.created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('registrations.created_at', '<=', $request->date_to);
        }

        $registrations = $query->orderBy('registrations.created_at', 'desc')->get();

        // Debug info
        $debug = [
            'total_in_db' => Registration::count(),
            'after_filters' => $registrations->count(),
            'semester' => $semester,
            'filters_applied' => $request->only([
                'semester',
                'department_id', 'major_id', 'payment_status',
                'academic_year', 'shift', 'gender', 'date_from', 'date_to'
            ]),
            'filters_that_were_filled' => array_filter($request->only([
                'semester',
                'department_id', 'major_id', 'payment_status',
                'academic_year', 'shift', 'gender', 'date_from', 'date_to'
            ])),
        ];

        // Transform registrations - include ALL data + ✅ period fields
        $transformedRegistrations = $registrations->map(function ($reg) use ($semester) {
            // Decide report payment status/amount based on semester selection
            $status = $reg->period_payment_status ?? $reg->payment_status ?? 'PENDING';
            $amount = $reg->period_tuition_amount ?? $reg->payment_amount ?? 100.00;
            $paidAt = $reg->period_paid_at ?? $reg->payment_date ?? null;

            $reportSemester = $reg->period_semester ?? ($semester ?: null);
            $reportAcademicYear = $reg->period_academic_year ?? $reg->academic_year ?? null;

            return [
                'id' => $reg->id,
                'first_name' => $reg->first_name,
                'last_name' => $reg->last_name,
                'full_name_en' => $reg->full_name_en,
                'full_name_kh' => $reg->full_name_kh,
                'gender' => $reg->gender,
                'date_of_birth' => $reg->date_of_birth,
                'personal_email' => $reg->personal_email,
                'phone_number' => $reg->phone_number,
                'address' => $reg->address,

                // ✅ correct per-semester payment info (when semester selected)
                'payment_status' => $status ?? 'PENDING',
                'payment_amount' => (float)$amount,
                'payment_date' => $paidAt,
                'semester' => $reportSemester,
                'academic_year' => $reportAcademicYear,

                'shift' => $reg->shift,
                'batch' => $reg->batch,
                'faculty' => $reg->faculty,
                'created_at' => $reg->created_at ? $reg->created_at->format('Y-m-d H:i:s') : null,

                // keep your nested relations
                'department' => $reg->department ? [
                    'id' => $reg->department->id,
                    'name' => $reg->department->name,
                    'code' => $reg->department->code ?? null,
                ] : null,
                'major' => $reg->major ? [
                    'id' => $reg->major->id,
                    'major_name' => $reg->major->major_name,
                ] : null,
                'student' => $reg->student ? [
                    'id' => $reg->student->id,
                    'student_code' => $reg->student->student_code,
                    'user_id' => $reg->student->user_id,
                ] : null,
            ];
        });

        // Stats: use transformed data so it matches what report shows
        $stats = [
            'total_registrations' => $transformedRegistrations->count(),
            'total_male' => $transformedRegistrations->where('gender', 'Male')->count(),
            'total_female' => $transformedRegistrations->where('gender', 'Female')->count(),
            'with_student_account' => $registrations->filter(fn($r) => $r->student !== null)->count(),
            'without_student_account' => $registrations->filter(fn($r) => $r->student === null)->count(),
            'payment_pending' => $transformedRegistrations->filter(fn($r) => in_array(strtoupper($r['payment_status'] ?? ''), ['PENDING', '']))->count(),
            'payment_completed' => $transformedRegistrations->filter(fn($r) => in_array(strtoupper($r['payment_status'] ?? ''), ['COMPLETED', 'PAID']))->count(),
            'total_amount' => (float)$transformedRegistrations->sum('payment_amount'),
            'paid_amount' => (float)$transformedRegistrations
                ->filter(fn($r) => in_array(strtoupper($r['payment_status'] ?? ''), ['COMPLETED', 'PAID']))
                ->sum('payment_amount'),
        ];

        $by_department = $registrations->filter(fn($reg) => $reg->department !== null)
            ->groupBy(fn($reg) => $reg->department->name)
            ->map(function ($items) {
                return [
                    'count' => $items->count(),
                    'male' => $items->where('gender', 'Male')->count(),
                    'female' => $items->where('gender', 'Female')->count(),
                ];
            });

        $by_major = $registrations->filter(fn($reg) => $reg->major !== null)
            ->groupBy(fn($reg) => $reg->major->major_name)
            ->map(function ($items) {
                return [
                    'count' => $items->count(),
                    'male' => $items->where('gender', 'Male')->count(),
                    'female' => $items->where('gender', 'Female')->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'debug' => $debug, // Remove this in production
            'data' => [
                'registrations' => $transformedRegistrations,
                'statistics' => $stats,
                'by_department' => $by_department,
                'by_major' => $by_major,
                'filters' => $request->all(),
            ]
        ]);
    }

    /**
     * Export registration report to PDF
     */
    public function exportPdf(Request $request)
    {
        $semester = (int)($request->input('semester', 0));

        $query = Registration::query()->with(['department', 'major', 'student']);

        if ($semester === 1 || $semester === 2) {
            $query->leftJoin('registration_periods as rp', function ($join) use ($semester) {
                $join->on('rp.registration_id', '=', 'registrations.id')
                     ->where('rp.semester', '=', $semester);
            });

            $query->select([
                'registrations.*',
                DB::raw('rp.payment_status as period_payment_status'),
                DB::raw('rp.paid_at as period_paid_at'),
                DB::raw('rp.tuition_amount as period_tuition_amount'),
                DB::raw('rp.semester as period_semester'),
                DB::raw('rp.academic_year as period_academic_year'),
            ]);
        }

        // Apply same filters as generate
        if ($request->filled('department_id')) {
            $query->where('registrations.department_id', $request->department_id);
        }

        if ($request->filled('major_id')) {
            $query->where('registrations.major_id', $request->major_id);
        }

        if ($request->filled('payment_status')) {
            if ($semester === 1 || $semester === 2) {
                $query->where('rp.payment_status', $request->payment_status);
            } else {
                $query->where('registrations.payment_status', $request->payment_status);
            }
        }

        if ($request->filled('academic_year')) {
            if ($semester === 1 || $semester === 2) {
                $query->where('rp.academic_year', $request->academic_year);
            } else {
                $query->where('registrations.academic_year', $request->academic_year);
            }
        }

        if ($request->filled('shift')) {
            $query->where('registrations.shift', $request->shift);
        }

        if ($request->filled('gender')) {
            $query->where('registrations.gender', $request->gender);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('registrations.created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('registrations.created_at', '<=', $request->date_to);
        }

        $registrations = $query->orderBy('registrations.created_at', 'desc')->get();

        // Build stats based on selected semester fields if present
        $rows = $registrations->map(function ($r) use ($semester) {
            $status = $r->period_payment_status ?? $r->payment_status ?? 'PENDING';
            $amount = $r->period_tuition_amount ?? $r->payment_amount ?? 0;
            return [
                'status' => strtoupper($status ?? 'PENDING'),
                'amount' => (float)($amount ?? 0),
            ];
        });

        $stats = [
            'total_registrations' => $registrations->count(),
            'total_male' => $registrations->where('gender', 'Male')->count(),
            'total_female' => $registrations->where('gender', 'Female')->count(),
            'payment_pending' => $rows->filter(fn($x) => $x['status'] === 'PENDING')->count(),
            'payment_completed' => $rows->filter(fn($x) => in_array($x['status'], ['COMPLETED', 'PAID']))->count(),
            'total_amount' => (float)$rows->sum('amount'),
            'paid_amount' => (float)$rows->filter(fn($x) => in_array($x['status'], ['COMPLETED', 'PAID']))->sum('amount'),
        ];

        $data = [
            'registrations' => $registrations,
            'stats' => $stats,
            'filters' => $request->all(),
            'semester' => $semester,
            'generated_date' => now()->format('F d, Y H:i:s'),
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.registration', $data);
        $pdf->setPaper('a4', 'landscape');

        $filename = 'registration_report_' . now()->format('YmdHis') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get report summary statistics
     */
    public function summary(Request $request)
    {
        $semester = (int)($request->input('semester', 0));

        $query = Registration::query();

        if ($semester === 1 || $semester === 2) {
            $query->leftJoin('registration_periods as rp', function ($join) use ($semester) {
                $join->on('rp.registration_id', '=', 'registrations.id')
                     ->where('rp.semester', '=', $semester);
            });

            $query->select([
                'registrations.*',
                DB::raw('rp.payment_status as period_payment_status'),
                DB::raw('rp.tuition_amount as period_tuition_amount'),
            ]);
        }

        if ($request->filled('academic_year')) {
            if ($semester === 1 || $semester === 2) {
                $query->where('rp.academic_year', $request->academic_year);
            } else {
                $query->where('registrations.academic_year', $request->academic_year);
            }
        }

        $registrations = $query->get();

        $summary = [
            'total_registrations' => $registrations->count(),
            'by_gender' => [
                'male' => $registrations->where('gender', 'Male')->count(),
                'female' => $registrations->where('gender', 'Female')->count(),
            ],
            'by_payment_status' => [
                'pending' => $registrations->filter(function ($r) {
                    $s = strtoupper($r->period_payment_status ?? $r->payment_status ?? 'PENDING');
                    return $s === 'PENDING';
                })->count(),
                'completed' => $registrations->filter(function ($r) {
                    $s = strtoupper($r->period_payment_status ?? $r->payment_status ?? '');
                    return in_array($s, ['COMPLETED', 'PAID']);
                })->count(),
                'failed' => $registrations->filter(function ($r) {
                    $s = strtoupper($r->period_payment_status ?? $r->payment_status ?? '');
                    return $s === 'FAILED';
                })->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
