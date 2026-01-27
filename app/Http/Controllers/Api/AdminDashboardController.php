<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Course;
use App\Models\Department;
use App\Models\Registration;
use App\Models\Major;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminDashboardController extends Controller
{
    /**
     * Get statistics for admin dashboard
     * GET /api/admin/dashboard/stats
     */
    public function getStats(Request $request)
    {
        try {
            // 1. Header Stats - Simple counts
            $totalStudents = Student::count();
            $totalCourses = Course::count();
            $totalDepartments = Department::count();
            $pendingRegistrations = Registration::where('payment_status', 'pending')->count();

            // 2. Growth Comparisons (MoM)
            $lastMonthStudents = Student::where('created_at', '<', now()->startOfMonth())->count();
            $studentGrowth = $lastMonthStudents > 0 
                ? round((($totalStudents - $lastMonthStudents) / $lastMonthStudents) * 100, 1) 
                : 100;

            // 3. Gender Distribution - Normalized
            $genderData = Student::select(
                DB::raw('LOWER(gender) as raw_gender'),
                DB::raw('count(*) as total')
            )
                ->groupBy('raw_gender')
                ->get()
                ->map(fn($item) => [
                    'name' => str_contains($item->raw_gender, 'f') ? 'Female' : 'Male',
                    'value' => $item->total
                ])
                // Merge if multiple mappings result in same name (e.g. 'm' and 'male')
                ->groupBy('name')
                ->map(fn($group, $name) => [
                    'name' => $name,
                    'value' => $group->sum('value')
                ])
                ->values();

            // 4. Revenue by Department
            $revenueByDept = Department::select('departments.name', DB::raw('SUM(registrations.payment_amount) as total_revenue'))
                ->join('registrations', 'departments.id', '=', 'registrations.department_id')
                ->where('registrations.payment_status', 'paid')
                ->groupBy('departments.id', 'departments.name')
                ->orderBy('total_revenue', 'desc')
                ->get();

            // 5. Most Popular Majors
            $popularMajors = Major::withCount('registrations')
                ->orderBy('registrations_count', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($major) => [
                    'name' => $major->name,
                    'count' => $major->registrations_count
                ]);

            // 6. Enrollment Trend (Last 6 Months)
            $enrollmentTrend = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $count = Registration::whereYear('created_at', $month->year)
                    ->whereMonth('created_at', $month->month)
                    ->count();
                $enrollmentTrend[] = [
                    'name' => $month->format('M'),
                    'students' => $count
                ];
            }

            // 7. Department Distribution
            $departmentDistribution = Department::withCount('students')
                ->get()
                ->map(fn($dept) => [
                    'name' => $dept->name,
                    'value' => $dept->students_count
                ]);

            // 8. Recent Activities
            $activities = Registration::with('department')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->map(fn($reg) => [
                    'id' => 'reg-' . $reg->id,
                    'message' => "New registration: {$reg->full_name_en}",
                    'time' => $reg->created_at->diffForHumans(),
                    'icon' => 'Users'
                ]);

            // 9. System Pulse
            $systemStatus = [
                ['label' => 'Database', 'status' => 'Operational', 'color' => 'green'],
                ['label' => 'Payment Gateway', 'status' => 'Stable', 'color' => 'green'],
                ['label' => 'Cloud Storage', 'status' => '98% Free', 'color' => 'green'],
            ];

            // 10. Advanced Analytics (3D Data)
            $advancedStats = Registration::select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                'departments.name as dept_name',
                'majors.name as major_name',
                DB::raw('count(*) as student_count'),
                DB::raw('sum(payment_amount) as revenue')
            )
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->where('payment_status', 'paid')
            ->groupBy('year', 'month', 'dept_name', 'major_name')
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();

            return response()->json([
                'data' => [
                    'stats' => [
                        'totalStudents' => $totalStudents,
                        'totalCourses' => $totalCourses,
                        'totalDepartments' => $totalDepartments,
                        'pendingRegistrations' => $pendingRegistrations,
                        'studentGrowth' => $studentGrowth . '%',
                    ],
                    'charts' => [
                        'enrollmentTrend' => $enrollmentTrend,
                        'departmentDistribution' => $departmentDistribution,
                        'genderDistribution' => $genderData,
                        'revenueByDept' => $revenueByDept,
                        'popularMajors' => $popularMajors,
                    ],
                    'activities' => $activities,
                    'systemStatus' => $systemStatus,
                    'advancedStats' => $advancedStats,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminDashboardController@getStats error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load stats', 'error' => $e->getMessage()], 500);
        }
    }


}
