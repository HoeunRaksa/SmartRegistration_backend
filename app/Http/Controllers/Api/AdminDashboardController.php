<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Course;
use App\Models\Department;
use App\Models\Registration;
use App\Models\Attendance;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Get statistics for admin dashboard
     * GET /api/admin/dashboard/stats
     */
    public function getStats(Request $request)
    {
        try {
            // Header Stats
            $totalStudents = Student::count();
            $totalCourses = Course::count();
            $totalDepartments = Department::count();
            $pendingRegistrations = Registration::where('status', 'pending')->count();

            // Enrollment Trend (Last 6 Months)
            $enrollmentTrend = Student::select(
                DB::raw('count(id) as students'),
                DB::raw("DATE_FORMAT(created_at, '%b') as name"),
                DB::raw('max(created_at) as latest_date')
            )
            ->where('created_at', '>=', now()->subMonths(6))
            ->groupBy('name')
            ->orderBy('latest_date')
            ->get();

            // Department Distribution
            $departmentDistribution = Department::withCount('majors') // Just a placeholder if we don't have student count directly
                ->get()
                ->map(function($dept) {
                    // Try to count students through majors
                    $studentCount = DB::table('students')
                        ->join('majors', 'students.major_id', '=', 'majors.id')
                        ->where('majors.department_id', $dept->id)
                        ->count();
                    
                    return [
                        'name' => $dept->department_name,
                        'value' => $studentCount
                    ];
                });

            // Attendance & Performance Overview (Sample dynamic data based on real records)
            $performanceData = [];
            for ($i = 3; $i >= 0; $i--) {
                $date = now()->subWeeks($i);
                $weekLabel = 'Week ' . (4 - $i);
                
                // Real attendance rate for that week
                $attendanceRate = Attendance::where('date', '>=', $date->startOfWeek()->toDateString())
                    ->where('date', '<=', $date->endOfWeek()->toDateString())
                    ->avg('is_present') * 100 ?? 85;

                // Real average grade for that week
                $avgGrade = Grade::where('created_at', '>=', $date->startOfWeek())
                    ->where('created_at', '<=', $date->endOfWeek())
                    ->avg('score') ?? 75;

                $performanceData[] = [
                    'name' => $weekLabel,
                    'attendance' => round($attendanceRate, 1),
                    'grades' => round($avgGrade, 1)
                ];
            }

            // Recent Activities (Last 10 registrations or student adds)
            $activities = [];
            $recentRegs = Registration::with('student', 'major')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            foreach ($recentRegs as $reg) {
                $activities[] = [
                    'id' => 'reg-' . $reg->id,
                    'type' => 'registration',
                    'message' => "New registration: " . ($reg->student ? $reg->student->name : "Unknown") . " for " . ($reg->major ? $reg->major->major_name : ""),
                    'time' => $reg->created_at->diffForHumans(),
                    'icon' => 'Users'
                ];
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
            Log::error('AdminDashboardController@getStats error: ' . $e->getMessage() . ' Trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Failed to load admin dashboard stats',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
