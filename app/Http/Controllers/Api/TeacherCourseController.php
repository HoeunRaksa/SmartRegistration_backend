<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeacherCourseController extends Controller
{
    /**
     * Get all courses for the authenticated teacher
     * GET /api/teacher/courses
     */
    public function index(Request $request)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();

            $courses = Course::with([
                    'majorSubject.subject',
                    'classGroup',
                    'classSchedules.room.building'
                ])
                ->withCount('enrollments')
                ->where('teacher_id', $teacher->id)
                ->get()
                ->map(function ($course) {
                    $subject = $course->majorSubject?->subject;
                    return [
                        'id' => $course->id,
                        'name' => $subject?->subject_name ?? 'N/A',
                        'code' => $subject?->subject_code ?? 'N/A',
                        'students' => $course->enrollments_count,
                        'semester' => $course->semester,
                        'academic_year' => $course->academic_year,
                        'class_group' => $course->classGroup?->class_name,
                        'schedules' => $course->classSchedules->map(function($s) {
                            return [
                                'day_of_week' => $s->day_of_week,
                                'time' => $s->start_time . ' - ' . $s->end_time,
                                'room' => ($s->room?->building?->name ?? '') . ' ' . ($s->room?->room_number ?? '')
                            ];
                        }),
                        'color' => 'from-blue-500 to-cyan-500' // Consistent fallback UI
                    ];
                });

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherCourseController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load courses'], 500);
        }
    }

    /**
     * Get details for a specific course
     * GET /api/teacher/courses/{id}
     */
    public function show(Request $request, $id)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();

            $course = Course::with([
                    'majorSubject.subject',
                    'classGroup',
                    'classSchedules.room.building',
                    'enrollments.student.user'
                ])
                ->where('teacher_id', $teacher->id)
                ->findOrFail($id);

            return response()->json(['data' => $course], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherCourseController@show error: ' . $e->getMessage());
            return response()->json(['message' => 'Course not found or access denied'], 404);
        }
    }

    /**
     * Get students enrolled in a specific course
     * GET /api/teacher/courses/{id}/students
     */
    public function getStudents(Request $request, $id)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            
            $course = Course::where('id', $id)
                ->where('teacher_id', $teacher->id)
                ->firstOrFail();

            $students = $course->enrollments()
                ->with(['student.user'])
                ->get()
                ->map(function($e) {
                    return [
                        'id' => $e->student->id,
                        'name' => $e->student->full_name,
                        'student_id' => $e->student->student_id_card,
                        'email' => $e->student->user?->email,
                    ];
                });

            return response()->json(['data' => $students], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherCourseController@getStudents error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load students'], 500);
        }
    }
}
