<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * Get audit logs with user information
     * Returns recent system activities for admin dashboard
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        
        // Query recent activities from various tables
        // This is a simplified version - you can expand based on your needs
        $logs = collect();
        
        // Recent student registrations
        $recentStudents = DB::table('students')
            ->select(
                'id',
                'name',
                'profile_picture_url',
                'created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT('New student enrolled: ', name) as description"),
                DB::raw("'registration' as action")
            )
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Recent teacher activities
        $recentTeachers = DB::table('teachers')
            ->select(
                'id',
                'name',
                'profile_picture_url',
                'created_at',
                DB::raw("'teacher' as user_type"),
                DB::raw("CONCAT('Teacher joined: ', name) as description"),
                DB::raw("'onboarding' as action")
            )
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
        
        // Recent course enrollments
        $recentEnrollments = DB::table('student_courses')
            ->join('students', 'student_courses.student_id', '=', 'students.id')
            ->join('courses', 'student_courses.course_id', '=', 'courses.id')
            ->select(
                'students.id',
                'students.name',
                'students.profile_picture_url',
                'student_courses.created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT(students.name, ' enrolled in ', courses.name) as description"),
                DB::raw("'enrollment' as action")
            )
            ->orderBy('student_courses.created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Recent attendance records
        $recentAttendance = DB::table('attendances')
            ->join('students', 'attendances.student_id', '=', 'students.id')
            ->join('class_sessions', 'attendances.class_session_id', '=', 'class_sessions.id')
            ->select(
                'students.id',
                'students.name',
                'students.profile_picture_url',
                'attendances.created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT(students.name, ' marked ', attendances.status, ' in class') as description"),
                DB::raw("'attendance' as action")
            )
            ->orderBy('attendances.created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Merge all logs
        $logs = $logs->merge($recentStudents)
            ->merge($recentTeachers)
            ->merge($recentEnrollments)
            ->merge($recentAttendance)
            ->sortByDesc('created_at')
            ->take($perPage)
            ->values();
        
        // Transform to match frontend expectations
        $transformedLogs = $logs->map(function ($log) {
            return [
                'id' => $log->id . '-' . $log->action,
                'user' => [
                    'name' => $log->name,
                    'profile_picture_url' => $log->profile_picture_url,
                ],
                'description' => $log->description,
                'action' => $log->action,
                'created_at' => $log->created_at,
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $transformedLogs,
        ]);
    }
}
