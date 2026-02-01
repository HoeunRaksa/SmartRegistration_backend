<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * Get audit logs with detailed metadata
     * Returns recent system activities for admin dashboard
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        
        $logs = collect();
        
        // Recent student registrations
        $recentStudents = DB::table('students')
            ->select(
                DB::raw("CONCAT('student-', id) as id"),
                'name',
                'email',
                'profile_picture_url',
                'created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT('New student enrolled: ', name) as description"),
                DB::raw("'CREATE STUDENT' as action"),
                DB::raw("'Students' as module"),
                DB::raw("'SUCCESS' as status"),
                DB::raw("'127.0.0.1' as ip_address")
            )
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Recent teacher activities
        $recentTeachers = DB::table('teachers')
            ->select(
                DB::raw("CONCAT('teacher-', id) as id"),
                'name',
                'email',
                'profile_picture_url',
                'created_at',
                DB::raw("'teacher' as user_type"),
                DB::raw("CONCAT('Teacher onboarded: ', name) as description"),
                DB::raw("'CREATE TEACHER' as action"),
                DB::raw("'Faculty' as module"),
                DB::raw("'SUCCESS' as status"),
                DB::raw("'127.0.0.1' as ip_address")
            )
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
        
        // Recent course enrollments
        $recentEnrollments = DB::table('student_courses')
            ->join('students', 'student_courses.student_id', '=', 'students.id')
            ->join('courses', 'student_courses.course_id', '=', 'courses.id')
            ->select(
                DB::raw("CONCAT('enrollment-', student_courses.id) as id"),
                'students.name',
                'students.email',
                'students.profile_picture_url',
                'student_courses.created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT(students.name, ' enrolled in ', courses.name) as description"),
                DB::raw("'ENROLL COURSE' as action"),
                DB::raw("'Courses' as module"),
                DB::raw("'SUCCESS' as status"),
                DB::raw("'127.0.0.1' as ip_address")
            )
            ->orderBy('student_courses.created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Recent attendance records
        $recentAttendance = DB::table('attendances')
            ->join('students', 'attendances.student_id', '=', 'students.id')
            ->select(
                DB::raw("CONCAT('attendance-', attendances.id) as id"),
                'students.name',
                'students.email',
                'students.profile_picture_url',
                'attendances.created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT(students.name, ' marked ', UPPER(attendances.status)) as description"),
                DB::raw("'MARK ATTENDANCE' as action"),
                DB::raw("'Attendance' as module"),
                DB::raw("CASE WHEN attendances.status = 'present' THEN 'SUCCESS' ELSE 'WARNING' END as status"),
                DB::raw("'127.0.0.1' as ip_address")
            )
            ->orderBy('attendances.created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Recent grade updates
        $recentGrades = DB::table('grades')
            ->join('students', 'grades.student_id', '=', 'students.id')
            ->join('courses', 'grades.course_id', '=', 'courses.id')
            ->select(
                DB::raw("CONCAT('grade-', grades.id) as id"),
                'students.name',
                'students.email',
                'students.profile_picture_url',
                'grades.updated_at as created_at',
                DB::raw("'student' as user_type"),
                DB::raw("CONCAT('Grade updated for ', students.name, ' in ', courses.name) as description"),
                DB::raw("'UPDATE GRADE' as action"),
                DB::raw("'Grades' as module"),
                DB::raw("'SUCCESS' as status"),
                DB::raw("'127.0.0.1' as ip_address")
            )
            ->orderBy('grades.updated_at', 'desc')
            ->limit(5)
            ->get();
        
        // Merge all logs
        $logs = $logs->merge($recentStudents)
            ->merge($recentTeachers)
            ->merge($recentEnrollments)
            ->merge($recentAttendance)
            ->merge($recentGrades)
            ->sortByDesc('created_at')
            ->take($perPage)
            ->values();
        
        // Transform to match frontend expectations
        $transformedLogs = $logs->map(function ($log) {
            return [
                'id' => $log->id,
                'user' => [
                    'name' => $log->name,
                    'email' => $log->email ?? 'N/A',
                    'profile_picture_url' => $log->profile_picture_url,
                ],
                'description' => $log->description,
                'action' => $log->action,
                'module' => $log->module,
                'status' => $log->status,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
                'timestamp' => \Carbon\Carbon::parse($log->created_at)->format('h:i A\nm/d/Y'),
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => $transformedLogs,
        ]);
    }
}
