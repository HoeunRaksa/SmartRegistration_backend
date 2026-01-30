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
    /**
     * Get enrolled courses with detailed info
     * GET /api/student/courses/enrolled
     */
    public function getEnrolledCourses(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $enrollments = CourseEnrollment::with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup',
                    'course.classSchedules.roomRef'
                ])
                ->where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->orderByDesc('enrolled_at')
                ->get();

            $courses = $enrollments->map(function ($enrollment) {
                $course = $enrollment->course;
                if (!$course) return null;

                $subject = $course->majorSubject?->subject;
                $teacher = $course->teacher;
                $schedules = $course->classSchedules ?? collect();

                return [
                    'enrollment_id' => $enrollment->id,
                    'course_id' => $course->id,
                    'course_code' => $subject?->subject_code ?? null,
                    'course_name' => $subject?->subject_name ?? null,
                    'credits' => $subject?->credits ?? 0,
                    'instructor' => [
                        'id' => $teacher?->id,
                        'name' => $teacher?->user?->name ?? null,
                    ],
                    'class_group' => $course->classGroup?->name ?? null,
                    'semester' => $course->semester,
                    'academic_year' => $course->academic_year,
                    'enrolled_at' => $enrollment->enrolled_at,
                    'progress' => $enrollment->progress ?? 0,
                    'schedule' => $schedules->map(fn($s) => [
                        'day' => $s->day_of_week,
                        'start_time' => $s->start_time,
                        'end_time' => $s->end_time,
                        'room' => $s->roomRef?->room_number ?? $s->room ?? null,
                    ]),
                ];
            })->filter()->values();

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCourseController@getEnrolledCourses error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load enrolled courses'], 500);
        }
    }

    /**
     * Get available courses for enrollment
     * GET /api/student/courses/available
     */
    public function getAvailableCourses(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            // Optimized: Use withCount to avoid N+1 query for enrollment counts
            $courses = Course::with([
                    'majorSubject.subject',
                    'teacher.user',
                    'classGroup',
                    'classSchedules.roomRef'
                ])
                ->withCount(['enrollments as enrolled_count' => function ($query) {
                    $query->where('status', 'enrolled');
                }])
                ->whereNotIn('id', $enrolledCourseIds)
                ->orderByDesc('id')
                ->get()
                ->map(function ($course) {
                    $subject = $course->majorSubject?->subject;
                    $teacher = $course->teacher;
                    $schedules = $course->classSchedules ?? collect();

                    return [
                        'course_id' => $course->id,
                        'course_code' => $subject?->subject_code ?? null,
                        'course_name' => $subject?->subject_name ?? null,
                        'description' => $subject?->description ?? null,
                        'credits' => $subject?->credits ?? 0,
                        'instructor' => [
                            'id' => $teacher?->id,
                            'name' => $teacher?->user?->name ?? null,
                        ],
                        'class_group' => $course->classGroup?->name ?? null,
                        'semester' => $course->semester,
                        'academic_year' => $course->academic_year,
                        'capacity' => $course->capacity ?? null,
                        'enrolled_count' => $course->enrolled_count, // Uses the eager loaded count
                        'schedule' => $schedules->map(fn($s) => [
                            'day' => $s->day_of_week,
                            'start_time' => $s->start_time,
                            'end_time' => $s->end_time,
                            'room' => $s->roomRef?->room_number ?? $s->room ?? null,
                        ]),
                    ];
                });

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCourseController@getAvailableCourses error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load available courses'], 500);
        }
    }

    /**
     * Get single course details
     * GET /api/student/courses/{courseId}
     */
    public function getCourse(Request $request, $courseId)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $course = Course::with([
                    'majorSubject.subject',
                    'teacher.user',
                    'classGroup',
                    'classSchedules.roomRef'
                ])
                ->findOrFail($courseId);

            $subject = $course->majorSubject?->subject;
            $teacher = $course->teacher;
            $schedules = $course->classSchedules ?? collect();

            // Check if student is enrolled
            $enrollment = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->first();

            return response()->json([
                'data' => [
                    'course_id' => $course->id,
                    'course_code' => $subject?->subject_code ?? null,
                    'course_name' => $subject?->subject_name ?? null,
                    'description' => $subject?->description ?? null,
                    'credits' => $subject?->credits ?? 0,
                    'instructor' => [
                        'id' => $teacher?->id,
                        'name' => $teacher?->user?->name ?? null,
                        'email' => $teacher?->user?->email ?? null,
                    ],
                    'class_group' => $course->classGroup?->name ?? null,
                    'semester' => $course->semester,
                    'academic_year' => $course->academic_year,
                    'is_enrolled' => $enrollment !== null && $enrollment->status === 'enrolled',
                    'enrollment_status' => $enrollment?->status ?? 'not_enrolled',
                    'enrolled_at' => $enrollment?->enrolled_at,
                    'schedule' => $schedules->map(fn($s) => [
                        'id' => $s->id,
                        'day' => $s->day_of_week,
                        'start_time' => $s->start_time,
                        'end_time' => $s->end_time,
                        'room' => $s->roomRef?->room_number ?? $s->room ?? null,
                        'building' => $s->roomRef?->building?->building_name ?? null,
                    ]),
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCourseController@getCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load course details'], 500);
        }
    }

    /**
     * Enroll in a course
     * POST /api/student/courses/enroll
     */
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
                return response()->json(['message' => 'Already enrolled in this course'], 409);
            }

            // Check course capacity
            $course = Course::find($request->course_id);
            if ($course->capacity) {
                $enrolledCount = CourseEnrollment::where('course_id', $course->id)
                    ->where('status', 'enrolled')
                    ->count();
                if ($enrolledCount >= $course->capacity) {
                    return response()->json(['message' => 'Course is full'], 409);
                }
            }

            if ($existing) {
                $existing->update([
                    'status' => 'enrolled',
                    'progress' => 0,
                    'dropped_at' => null,
                    'enrolled_at' => now(),
                ]);
                $enrollment = $existing;
            } else {
                $enrollment = CourseEnrollment::create([
                    'student_id' => $student->id,
                    'course_id' => $request->course_id,
                    'status' => 'enrolled',
                    'progress' => 0,
                    'enrolled_at' => now(),
                ]);
            }

            DB::commit();
            return response()->json([
                'message' => 'Enrolled successfully',
                'data' => ['enrollment_id' => $enrollment->id]
            ], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StudentCourseController@enrollCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to enroll in course'], 500);
        }
    }

    /**
     * Drop a course
     * DELETE /api/student/courses/{courseId}/drop
     */
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
            return response()->json(['message' => 'Course dropped successfully'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StudentCourseController@dropCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to drop course'], 500);
        }
    }

    /**
     * Get enrollment history
     * GET /api/student/courses/history
     */
    public function getEnrollmentHistory(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $history = CourseEnrollment::with(['course.majorSubject.subject'])
                ->where('student_id', $student->id)
                ->orderByDesc('enrolled_at')
                ->get()
                ->map(function ($enrollment) {
                    $course = $enrollment->course;
                    $subject = $course?->majorSubject?->subject;

                    return [
                        'enrollment_id' => $enrollment->id,
                        'course_id' => $course?->id,
                        'course_code' => $subject?->subject_code ?? null,
                        'course_name' => $subject?->subject_name ?? null,
                        'credits' => $subject?->credits ?? 0,
                        'status' => $enrollment->status,
                        'enrolled_at' => $enrollment->enrolled_at,
                        'dropped_at' => $enrollment->dropped_at,
                        'completed_at' => $enrollment->completed_at ?? null,
                    ];
                });

            return response()->json(['data' => $history], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCourseController@getEnrollmentHistory error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load enrollment history'], 500);
        }
    }
}
