<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Registration;
use App\Models\Student;
use App\Models\Department;
use App\Models\Major;

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
        // Start with all registrations (not filtered by any role or student status)
        $query = Registration::with(['department', 'major', 'student']);

        // Apply filters ONLY if they have values
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('major_id')) {
            $query->where('major_id', $request->major_id);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->academic_year);
        }

        if ($request->filled('shift')) {
            $query->where('shift', $request->shift);
        }

        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Get ALL registrations (including those without students)
        $registrations = $query->orderBy('created_at', 'desc')->get();

        // Debug info
        $debug = [
            'total_in_db' => Registration::count(),
            'after_filters' => $registrations->count(),
            'filters_applied' => $request->only([
                'department_id', 'major_id', 'payment_status',
                'academic_year', 'shift', 'gender', 'date_from', 'date_to'
            ]),
            'filters_that_were_filled' => array_filter($request->only([
                'department_id', 'major_id', 'payment_status',
                'academic_year', 'shift', 'gender', 'date_from', 'date_to'
            ])),
        ];

        // Transform registrations - include ALL data
        $transformedRegistrations = $registrations->map(function ($reg) {
            // Normalize payment status to avoid null/empty
            $status = $reg->payment_status;
            if ($status === null || trim((string)$status) === '') {
                $status = 'PENDING';
            }

            // Normalize amount default (keep consistent everywhere)
            $amount = $reg->payment_amount;
            if ($amount === null || $amount === '') {
                $amount = 100.00;
            }

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

                'payment_status' => $status,
                'payment_amount' => (float)$amount,
                'payment_date' => $reg->payment_date,

                'shift' => $reg->shift,
                'batch' => $reg->batch,
                'academic_year' => $reg->academic_year,
                'faculty' => $reg->faculty,

                'created_at' => $reg->created_at ? $reg->created_at->format('Y-m-d H:i:s') : null,

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

        // Calculate statistics - ALL registrations
        $stats = [
            'total_registrations' => $registrations->count(),
            'total_male' => $registrations->where('gender', 'Male')->count(),
            'total_female' => $registrations->where('gender', 'Female')->count(),
            'with_student_account' => $registrations->filter(fn($r) => $r->student !== null)->count(),
            'without_student_account' => $registrations->filter(fn($r) => $r->student === null)->count(),

            'payment_pending' => $registrations->filter(function ($r) {
                $s = $r->payment_status;
                return $s === null || trim((string)$s) === '' || strtoupper(trim((string)$s)) === 'PENDING';
            })->count(),

            'payment_completed' => $registrations->filter(function ($r) {
                $s = strtoupper(trim((string)($r->payment_status ?? '')));
                return in_array($s, ['COMPLETED', 'PAID'], true);
            })->count(),

            // total_amount should count all registrations (default to 100.00 if null)
            'total_amount' => (float)$registrations->sum(function ($r) {
                return (float)($r->payment_amount ?? 100.00);
            }),

            // paid_amount only for completed/paid
            'paid_amount' => (float)$registrations->filter(function ($r) {
                $s = strtoupper(trim((string)($r->payment_status ?? '')));
                return in_array($s, ['COMPLETED', 'PAID'], true);
            })->sum(function ($r) {
                return (float)($r->payment_amount ?? 100.00);
            }),
        ];

        // Group by department
        $by_department = $registrations->filter(function ($reg) {
            return $reg->department !== null;
        })->groupBy(function ($reg) {
            return $reg->department->name;
        })->map(function ($items) {
            return [
                'count' => $items->count(),
                'male' => $items->where('gender', 'Male')->count(),
                'female' => $items->where('gender', 'Female')->count(),
            ];
        });

        // Group by major
        $by_major = $registrations->filter(function ($reg) {
            return $reg->major !== null;
        })->groupBy(function ($reg) {
            return $reg->major->major_name;
        })->map(function ($items) {
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
        $query = Registration::with(['department', 'major', 'student']);

        // Apply same filters as generate
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('major_id')) {
            $query->where('major_id', $request->major_id);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->academic_year);
        }

        if ($request->filled('shift')) {
            $query->where('shift', $request->shift);
        }

        if ($request->filled('gender')) {
            $query->where('gender', $request->gender);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $registrations = $query->orderBy('created_at', 'desc')->get();

        // Calculate statistics (keep consistent default amount)
        $stats = [
            'total_registrations' => $registrations->count(),
            'total_male' => $registrations->where('gender', 'Male')->count(),
            'total_female' => $registrations->where('gender', 'Female')->count(),
            'payment_pending' => $registrations->filter(function ($r) {
                $s = $r->payment_status;
                return $s === null || trim((string)$s) === '' || strtoupper(trim((string)$s)) === 'PENDING';
            })->count(),
            'payment_completed' => $registrations->filter(function ($r) {
                $s = strtoupper(trim((string)($r->payment_status ?? '')));
                return in_array($s, ['COMPLETED', 'PAID'], true);
            })->count(),

            'total_amount' => (float)$registrations->sum(function ($r) {
                return (float)($r->payment_amount ?? 100.00);
            }),

            'paid_amount' => (float)$registrations->filter(function ($r) {
                $s = strtoupper(trim((string)($r->payment_status ?? '')));
                return in_array($s, ['COMPLETED', 'PAID'], true);
            })->sum(function ($r) {
                return (float)($r->payment_amount ?? 100.00);
            }),
        ];

        // Prepare data for PDF
        $data = [
            'registrations' => $registrations,
            'stats' => $stats,
            'filters' => $request->all(),
            'generated_date' => now()->format('F d, Y H:i:s'),
        ];

        // Generate PDF
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.registration', $data);
        $pdf->setPaper('a4', 'landscape');

        // Generate filename
        $filename = 'registration_report_' . now()->format('YmdHis') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get report summary statistics
     */
    public function summary(Request $request)
    {
        $query = Registration::query();

        // Apply filters if provided
        if ($request->filled('academic_year')) {
            $query->where('academic_year', $request->academic_year);
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
                    $s = $r->payment_status;
                    return $s === null || trim((string)$s) === '' || strtoupper(trim((string)$s)) === 'PENDING';
                })->count(),
                'completed' => $registrations->filter(function ($r) {
                    $s = strtoupper(trim((string)($r->payment_status ?? '')));
                    return in_array($s, ['COMPLETED', 'PAID'], true);
                })->count(),
                'failed' => $registrations->where('payment_status', 'FAILED')->count(),
            ],
            'by_shift' => $registrations->groupBy('shift')->map->count(),
            'financial' => [
                'total_amount' => (float)$registrations->sum(function ($r) {
                    return (float)($r->payment_amount ?? 100.00);
                }),
                'paid_amount' => (float)$registrations->filter(function ($r) {
                    $s = strtoupper(trim((string)($r->payment_status ?? '')));
                    return in_array($s, ['COMPLETED', 'PAID'], true);
                })->sum(function ($r) {
                    return (float)($r->payment_amount ?? 100.00);
                }),
                'pending_amount' => (float)$registrations->filter(function ($r) {
                    $s = $r->payment_status;
                    return $s === null || trim((string)$s) === '' || strtoupper(trim((string)$s)) === 'PENDING';
                })->sum(function ($r) {
                    return (float)($r->payment_amount ?? 100.00);
                }),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}
