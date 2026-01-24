<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Course;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminEnrollmentController extends Controller
{
    /**
     * GET /api/admin/enrollments
     * Admin overview
     */
 /**
 * GET /api/admin/enrollments
 * Query params (all optional):
 * - department_id
 * - major_id
 * - status (enrolled|completed|dropped)
 * - academic_year (ex: 2025-2026)
 * - semester (1|2|3)
 * - q (search: student code/name, course name)
 */
public function index(Request $request)
{
    try {
        $departmentId = $request->query('department_id');
        $status       = $request->query('status');
        $academicYear = $request->query('academic_year');
        $semester     = $request->query('semester');
        $q            = trim((string)$request->query('q', ''));

        $enrollmentsQ = \App\Models\CourseEnrollment::query()
            ->with([
                // ✅ student + user email
                'student' => function ($sq) {
                    $sq->select(
                        'id',
                        'user_id',
                        'student_code',
                        'full_name_en',
                        'full_name_kh',
                        'department_id',
                        'phone_number',
                        'address',
                        'profile_picture_path'
                    )->with([
                        'user:id,email,name,profile_picture_path'
                    ]);
                },

                'course' => function ($cq) {
                    $cq->with([
                        'classGroup:id,class_name',
                        'majorSubject.subject:id,subject_name',
                        'majorSubject.major:id,major_name,department_id',
                        'teacher:id,name,full_name',
                    ]);
                },
            ]);

        if (!empty($departmentId)) {
            $enrollmentsQ->whereHas('student', fn($sq) => $sq->where('department_id', $departmentId));
        }

        if (!empty($status)) {
            $enrollmentsQ->where('status', $status);
        }

        if (!empty($academicYear)) {
            $enrollmentsQ->whereHas('course', fn($cq) => $cq->where('academic_year', $academicYear));
        }

        if (!empty($semester)) {
            $enrollmentsQ->whereHas('course', fn($cq) => $cq->where('semester', (int)$semester));
        }

        if ($q !== '') {
            $enrollmentsQ->where(function ($root) use ($q) {
                $root->whereHas('student', function ($sq) use ($q) {
                    $sq->where('student_code', 'like', "%{$q}%")
                        ->orWhere('full_name_en', 'like', "%{$q}%")
                        ->orWhere('full_name_kh', 'like', "%{$q}%")
                        // ✅ search user email too
                        ->orWhereHas('user', fn($uq) => $uq->where('email', 'like', "%{$q}%"));
                })
                ->orWhereHas('course.majorSubject.subject', fn($subQ) => $subQ->where('subject_name', 'like', "%{$q}%"))
                ->orWhereHas('course.classGroup', fn($cgQ) => $cgQ->where('class_name', 'like', "%{$q}%"));
            });
        }

        $enrollments = $enrollmentsQ->orderByDesc('id')->get()->map(function ($e) {
            $student = $e->student;
            $user    = $student?->user;

            $studentName = $student?->full_name_en ?: ($student?->full_name_kh ?: null);

            // ✅ profile image: student.profile_picture_path OR user.profile_picture_path
            $path = $student?->profile_picture_path ?: $user?->profile_picture_path;
            $profileUrl = $path ? asset('uploads/profiles/' . basename($path)) : null;

            return [
                'id' => $e->id,

                'student_id'    => $e->student_id,
                'student_code'  => $student?->student_code,
                'student_name'  => $studentName,
                'department_id' => $student?->department_id,

                // ✅ THIS is what fixes "Email N/A"
                'email'   => $user?->email ?? null,
                'phone'   => $student?->phone_number ?? null,
                'address' => $student?->address ?? null,

                'profile_picture_url' => $profileUrl,

                'course_id'     => $e->course_id,
                'course_name'   => $e->course?->display_name,
                'academic_year' => $e->course?->academic_year,
                'semester'      => $e->course?->semester,

                'status'      => $e->status,
                'progress'    => $e->progress,
                'enrolled_at' => $e->enrolled_at,
                'dropped_at'  => $e->dropped_at,

                'created_at' => $e->created_at,
                'updated_at' => $e->updated_at,
            ];
        });

        return response()->json(['data' => $enrollments], 200);
    } catch (\Throwable $e) {
        Log::error('AdminEnrollmentController@index error', ['message' => $e->getMessage()]);
        return response()->json(['message' => 'Failed to load enrollments'], 500);
    }
}





    /**
     * POST /api/admin/enrollments
     * Manual enroll OR re-enroll (next year, repeat class)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_id'  => 'required|exists:courses,id',
            'status'     => 'nullable|in:enrolled,completed,dropped',
        ]);

        $status = $data['status'] ?? 'enrolled';

        $course = Course::findOrFail($data['course_id']);

        /**
         * IMPORTANT:
         * Enrollment uniqueness is:
         * student_id + course_id
         * (course already includes academic_year + semester)
         */
        $existing = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $data['course_id'])
            ->first();

        if ($existing) {
            // Already enrolled in THIS course instance
            return response()->json([
                'message' => 'Student already enrolled in this course.',
            ], 409);
        }

        $enrollment = CourseEnrollment::create([
            'student_id'  => $data['student_id'],
            'course_id'   => $data['course_id'],
            'status'      => $status,
            'progress'    => $status === 'enrolled' ? 0 : null,
            'enrolled_at' => $status === 'enrolled' ? now() : null,
            'dropped_at'  => $status === 'dropped' ? now() : null,
        ]);

        return response()->json([
            'message' => 'Enrollment created',
            'data' => $enrollment->load(['student', 'course']),
        ], 201);
    }

    /**
     * PUT /api/admin/enrollments/{id}/status
     * Human decision: complete / drop / re-enroll
     */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => 'required|in:enrolled,completed,dropped',
        ]);

        try {
            $enrollment = CourseEnrollment::findOrFail($id);

            $update = ['status' => $data['status']];

            if ($data['status'] === 'enrolled') {
                $update['enrolled_at'] = now();
                $update['dropped_at']  = null;
                $update['progress']    = 0;
            }

            if ($data['status'] === 'completed') {
                $update['progress'] = 100;
            }

            if ($data['status'] === 'dropped') {
                $update['dropped_at'] = now();
            }

            $enrollment->update($update);

            return response()->json([
                'message' => 'Enrollment updated',
                'data' => $enrollment->load(['student', 'course']),
            ]);
        } catch (\Throwable $e) {
            Log::error('Update enrollment status failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to update enrollment'], 500);
        }
    }

    /**
     * DELETE /api/admin/enrollments/{id}
     * Hard delete (admin only)
     */
    public function destroy($id)
    {
        try {
            CourseEnrollment::findOrFail($id)->delete();
            return response()->json(['message' => 'Enrollment deleted'], 200);
        } catch (\Throwable $e) {
            Log::error('Delete enrollment failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to delete enrollment'], 500);
        }
    }
}
