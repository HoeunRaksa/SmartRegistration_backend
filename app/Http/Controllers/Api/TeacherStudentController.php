<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Teacher;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeacherStudentController extends Controller
{
    /**
     * Get all unique students enrolled in courses taught by the teacher
     * GET /api/teacher/students
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            $teacher = Teacher::where('user_id', $user->id)->first();
            if (!$teacher) {
                return response()->json(['data' => []], 200);
            }
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $studentIds = CourseEnrollment::whereIn('course_id', $courseIds)
                ->where('status', 'enrolled')
                ->distinct('student_id')
                ->pluck('student_id');

            $students = Student::with(['user', 'department'])
                ->whereIn('id', $studentIds)
                ->get()
                ->map(function($s) {
                    return [
                        'id' => $s->id,
                        'full_name' => $s->full_name,
                        'email' => $s->user?->email,
                        'student_id_card' => $s->student_code,
                        'department' => $s->department?->name,
                        'status' => 'active',
                        'profile_picture_url' => $s->user?->profile_picture_url,
                    ];
                });

            return response()->json(['data' => $students], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherStudentController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load students'], 500);
        }
    }
}
