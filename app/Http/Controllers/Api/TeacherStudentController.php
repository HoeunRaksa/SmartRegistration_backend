<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\FriendRequest;
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

            $enrollments = CourseEnrollment::with(['student.user', 'student.department', 'course.majorSubject.subject', 'course.classGroup'])
                ->whereIn('course_id', $courseIds)
                ->where('status', 'enrolled')
                ->get();

            $studentsMap = [];

            foreach ($enrollments as $e) {
                $s = $e->student;
                if (!$s) continue;

                if (!isset($studentsMap[$s->id])) {
                    $profileUrl = null;
                    if ($s->user && $s->user->profile_picture_path) {
                        $profileUrl = url('uploads/profiles/' . basename($s->user->profile_picture_path));
                    }

                    // Check connection status
                    $existing = FriendRequest::where(function($q) use ($user, $s) {
                        $q->where('sender_id', $user->id)->where('receiver_id', $s->user_id);
                    })->orWhere(function($q) use ($user, $s) {
                        $q->where('sender_id', $s->user_id)->where('receiver_id', $user->id);
                    })->first();

                    $studentsMap[$s->id] = [
                        'id' => $s->id,
                        'user_id' => $s->user_id,
                        'full_name' => $s->full_name_en ?: $s->full_name_kh,
                        'email' => $s->user?->email,
                        'student_id_card' => $s->student_code,
                        'department' => $s->department?->name,
                        'status' => 'active',
                        'profile_picture_url' => $profileUrl,
                        'connection_status' => $existing ? $existing->status : null,
                        'courses' => []
                    ];
                }

                $studentsMap[$s->id]['courses'][] = [
                    'id' => $e->course_id,
                    'course_name' => $e->course?->majorSubject?->subject?->subject_name,
                    'class_name' => $e->course?->classGroup?->class_name,
                ];
            }

            return response()->json(['data' => array_values($studentsMap)], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherStudentController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load students'], 500);
        }
    }
}
