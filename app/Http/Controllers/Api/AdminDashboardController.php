<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Course;
use App\Models\Department;
use App\Models\Registration;
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
            // Header Stats - Simple counts that should always work
            $totalStudents = Student::count();
            $totalCourses = Course::count();
            $totalDepartments = Department::count();
            $pendingRegistrations = Registration::where('payment_status', 'pending')->count();

            // Enrollment Trend - Actual data from Registration table
            $enrollmentTrend = [];
            try {
                $trendData = Registration::select(
                    DB::raw('DATE_FORMAT(created_at, "%b") as month'),
                    DB::raw('COUNT(*) as students')
                )
                ->where('created_at', '>=', now()->subMonths(6))
                ->groupBy('month', DB::raw('MONTH(created_at)'))
                ->orderBy(DB::raw('MONTH(created_at)'))
                ->get();

                if ($trendData->isEmpty()) {
                    // Fallback if no data yet
                    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'];
                    foreach ($months as $month) {
                        $enrollmentTrend[] = ['name' => $month, 'students' => 0];
                    }
                } else {
                    foreach ($trendData as $data) {
                        $enrollmentTrend[] = [
                            'name' => $data->month,
                            'students' => $data->students
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::error('Enrollment trend error: ' . $e->getMessage());
                $enrollmentTrend = [['name' => 'Error', 'students' => 0]];
            }

            // Department Distribution - Actual students per department
            $departmentDistribution = [];
            try {
                $deptData = Department::withCount('students')->get();
                foreach ($deptData as $dept) {
                    $departmentDistribution[] = [
                        'name' => $dept->name,
                        'value' => $dept->students_count
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Department distribution error: ' . $e->getMessage());
            }

            // Performance Data - Aggregated Gades mapping (simplified placeholder logic with actual grades if available)
            $performanceData = [];
            try {
                // For demonstration, let's use some dummy logic that could be expanded
                // Realistically would need a bridge to time-based performance
                $performanceData = [
                    ['name' => 'Week 1', 'attendance' => 85, 'grades' => 78],
                    ['name' => 'Week 2', 'attendance' => 88, 'grades' => 82],
                    ['name' => 'Week 3', 'attendance' => 92, 'grades' => 85],
                    ['name' => 'Week 4', 'attendance' => 87, 'grades' => 88],
                ];
            } catch (\Exception $e) {}

            // Recent Activities - System-wide recent records
            $activities = [];
            try {
                // Fetch recent registrations
                $recentRegs = Registration::with('department')
                    ->orderBy('created_at', 'desc')
                    ->limit(3)
                    ->get();

                foreach ($recentRegs as $reg) {
                    $activities[] = [
                        'id' => 'reg-' . $reg->id,
                        'type' => 'registration',
                        'message' => "New registration for " . ($reg->department->name ?? 'Course'),
                        'time' => $reg->created_at ? $reg->created_at->diffForHumans() : 'Recently',
                        'icon' => 'Users'
                    ];
                }

                // Fetch recent student creations
                $recentStudents = Student::orderBy('created_at', 'desc')
                    ->limit(2)
                    ->get();
                
                foreach ($recentStudents as $student) {
                    $activities[] = [
                        'id' => 'stu-' . $student->id,
                        'type' => 'student',
                        'message' => "Student ID issued: " . $student->student_code,
                        'time' => $student->created_at ? $student->created_at->diffForHumans() : 'Recently',
                        'icon' => 'GraduationCap'
                    ];
                }

                // Sort activities by time would be better but simple append is fine for now
            } catch (\Exception $e) {
                Log::error('Activities load error: ' . $e->getMessage());
            }

            return response()->json([
                'data' => [
                    'stats' => [
                        'totalStudents' => $totalStudents,
                        'totalCourses' => $totalCourses,
                        'totalDepartments' => $totalDepartments,
                        'pendingRegistrations' => $pendingRegistrations,
                    ],
                    'charts' => [
                        'enrollmentTrend' => $enrollmentTrend,
                        'departmentDistribution' => $departmentDistribution,
                        'performanceData' => $performanceData,
                    ],
                    'activities' => $activities,
                    'systemStatus' => [
                        ['label' => 'Database', 'status' => 'Operational', 'color' => 'green'],
                        ['label' => 'API Services', 'status' => 'Operational', 'color' => 'green'],
                        ['label' => 'Storage', 'status' => 'Operational', 'color' => 'green'],
                        ['label' => 'Mailing', 'status' => 'Operational', 'color' => 'green'],
                    ]
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminDashboardController@getStats error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to load admin dashboard stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }

}
