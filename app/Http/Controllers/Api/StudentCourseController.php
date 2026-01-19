<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class StudentCourseController extends Controller
{
    public function getEnrolledCourses(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courses = CourseEnrollment::with('course')
                ->where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->orderByDesc('enrolled_at')
                ->get();

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCourseController@getEnrolledCourses error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load enrolled courses'], 500);
        }
    }

    public function getAvailableCourses(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $courses = Course::whereNotIn('id', $enrolledCourseIds)->orderByDesc('id')->get();

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCourseController@getAvailableCourses error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load available courses'], 500);
        }
    }

    public function enrollCourse(Request $request)
    {
        $request->validate([
            'course_id' => 'required|exists:courses,id',
        ]);

        DB::beginTransaction();

        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $existing = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $request->course_id)
                ->first();

            if ($existing && $existing->status === 'enrolled') {
                return response()->json(['message' => 'Already enrolled'], 409);
            }

            if ($existing) {
                $existing->update([
                    'status' => 'enrolled',
                    'progress' => 0,
                    'dropped_at' => null,
                    'enrolled_at' => now(),
                ]);
            } else {
                CourseEnrollment::create([
                    'student_id' => $student->id,
                    'course_id' => $request->course_id,
                    'status' => 'enrolled',
                    'progress' => 0,
                    'enrolled_at' => now(),
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Enrolled successfully'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StudentCourseController@enrollCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to enroll course'], 500);
        }
    }

    public function dropCourse(Request $request, $courseId)
    {
        DB::beginTransaction();

        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $enrollment = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->where('status', 'enrolled')
                ->firstOrFail();

            $enrollment->update([
                'status' => 'dropped',
                'dropped_at' => now(),
            ]);

            DB::commit();
            return response()->json(['message' => 'Dropped successfully'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StudentCourseController@dropCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to drop course'], 500);
        }
    }
}
