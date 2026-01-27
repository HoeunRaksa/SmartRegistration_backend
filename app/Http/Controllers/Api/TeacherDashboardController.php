<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Teacher;
use App\Models\ClassSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeacherDashboardController extends Controller
{
    /**
     * Get statistics for teacher dashboard
     * GET /api/teacher/dashboard/stats
     */
    public function getStats(Request $request)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $totalStudents = CourseEnrollment::whereIn('course_id', $courseIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->count();

            $totalCourses = $courseIds->count();

            $upcomingSessions = ClassSession::with(['course.majorSubject.subject'])
                ->whereIn('course_id', $courseIds)
                ->where('session_date', '>=', now()->toDateString())
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->limit(5)
                ->get()
                ->map(function($s) {
                    return [
                        'id' => $s->id,
                        'course' => $s->course?->majorSubject?->subject?->subject_name,
                        'date' => $s->session_date,
                        'time' => $s->start_time . ' - ' . $s->end_time,
                    ];
                });

            return response()->json([
                'data' => [
                    'total_students' => $totalStudents,
                    'total_courses' => $totalCourses,
                    'upcoming_sessions' => $upcomingSessions,
                    'years_teaching' => 4, // Placeholder if not in DB
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherDashboardController@getStats error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load dashboard stats'], 500);
        }
    }
}
