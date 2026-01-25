<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminEnrollmentController extends Controller
{
    /**
     * GET /api/admin/enrollments
     * Query params (all optional):
     * - department_id
     * - major_id
     * - course_id
     * - status (enrolled|completed|dropped)
     * - academic_year (ex: 2025-2026)
     * - semester (1|2|3)
     * - q (search: student code/name, course fields)
     * - per_page (default 20)
     */
    public function index(Request $request)
    {
        try {
            $departmentId = $request->query('department_id');
            $majorId      = $request->query('major_id');
            $courseId     = $request->query('course_id');
            $status       = $request->query('status');
            $academicYear = $request->query('academic_year');
            $semester     = $request->query('semester');
            $q            = trim((string) $request->query('q', ''));

            $perPage = (int) $request->query('per_page', 20);
            if ($perPage <= 0) $perPage = 20;
            if ($perPage > 200) $perPage = 200;

            $enrollmentsQ = CourseEnrollment::query()
                ->with([
                    'student:id,user_id,registration_id,student_code,full_name_en,full_name_kh,department_id,phone_number,address',
                    'student.user:id,email,profile_picture_path',
                    'student.registration:id,major_id,department_id',
                    'student.registration.major:id,major_name,department_id',
                    'course' => function ($cq) {
                        $cq->with([
                            'classGroup:id,class_name',
                            'majorSubject.subject:id,subject_name',
                            'majorSubject.major:id,major_name,department_id',
                            'teacher:id,full_name',
                        ]);
                    },
                ]);

            /* ================= FILTERS ================= */

            if (!empty($departmentId)) {
                $enrollmentsQ->whereHas('student', function ($sq) use ($departmentId) {
                    $sq->where('department_id', $departmentId);
                });
            }

            if (!empty($majorId)) {
                $enrollmentsQ->whereHas('student.registration', function ($rq) use ($majorId) {
                    $rq->where('major_id', $majorId);
                });
            }

            if (!empty($courseId)) {
                $enrollmentsQ->where('course_id', $courseId);
            }

            if (!empty($status)) {
                $enrollmentsQ->where('status', $status);
            }

            if (!empty($academicYear)) {
                $enrollmentsQ->whereHas('course', function ($cq) use ($academicYear) {
                    $cq->where('academic_year', $academicYear);
                });
            }

            if (!empty($semester)) {
                $enrollmentsQ->whereHas('course', function ($cq) use ($semester) {
                    $cq->where('semester', (int) $semester);
                });
            }

            /* ================= SEARCH ================= */

            if ($q !== '') {
                $enrollmentsQ->where(function ($root) use ($q) {
                    $root->whereHas('student', function ($sq) use ($q) {
                        $sq->where('student_code', 'like', "%{$q}%")
                            ->orWhere('full_name_en', 'like', "%{$q}%")
                            ->orWhere('full_name_kh', 'like', "%{$q}%")
                            ->orWhere('phone_number', 'like', "%{$q}%")
                            ->orWhere('address', 'like', "%{$q}%");
                    })
                    ->orWhereHas('student.user', function ($uq) use ($q) {
                        $uq->where('email', 'like', "%{$q}%");
                    })
                    ->orWhereHas('student.registration.major', function ($mq) use ($q) {
                        $mq->where('major_name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('course.majorSubject.subject', function ($subQ) use ($q) {
                        $subQ->where('subject_name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('course.classGroup', function ($cgQ) use ($q) {
                        $cgQ->where('class_name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('course.teacher', function ($tQ) use ($q) {
                        $tQ->where('full_name', 'like', "%{$q}%");
                    });
                });
            }

            // ✅ paginate to avoid loading everything
            $paginator = $enrollmentsQ
                ->orderByDesc('id')
                ->paginate($perPage);

            $data = collect($paginator->items())->map(function ($e) {
                $student = $e->student;
                $course  = $e->course;

                $studentName = $student?->full_name_en ?: ($student?->full_name_kh ?: null);

                // ✅ EXACT SAME SOURCE AS StudentController:
                // url('uploads/profiles/' . basename($student->user->profile_picture_path))
                $profileUrl = null;
                if ($student?->user && $student->user->profile_picture_path) {
                    $profileUrl = url('uploads/profiles/' . basename($student->user->profile_picture_path));
                }

                $regMajorName = $student?->registration?->major?->major_name;

                return [
                    'id' => $e->id,

                    // flat fields
                    'student_id'    => $e->student_id,
                    'student_code'  => $student?->student_code,
                    'student_name'  => $studentName,
                    'department_id' => $student?->department_id,

                    'email'   => $student?->user?->email,
                    'phone'   => $student?->phone_number,
                    'address' => $student?->address,
                    'profile_picture_url' => $profileUrl,

                    // nested student
                    'student' => [
                        'id' => $student?->id,
                        'student_code' => $student?->student_code,
                        'full_name_en' => $student?->full_name_en,
                        'full_name_kh' => $student?->full_name_kh,
                        'department_id' => $student?->department_id,
                        'phone_number' => $student?->phone_number,
                        'address' => $student?->address,
                        'profile_picture_url' => $profileUrl,
                        'user' => [
                            'email' => $student?->user?->email,
                            'profile_picture_path' => $student?->user?->profile_picture_path,
                        ],
                    ],

                    'course_id'     => $e->course_id,
                    'course_name'   => $course?->display_name,
                    'academic_year' => $course?->academic_year,
                    'semester'      => $course?->semester,

                    'teacher_name' => $course?->teacher?->full_name,
                    'class_name'   => $course?->classGroup?->class_name,
                    'subject_name' => $course?->majorSubject?->subject?->subject_name,

                    'major_name' => $regMajorName,

                    'status'      => $e->status,
                    'progress'    => $e->progress,
                    'enrolled_at' => $e->enrolled_at,
                    'dropped_at'  => $e->dropped_at,

                    'created_at' => $e->created_at,
                    'updated_at' => $e->updated_at,
                ];
            });

            return response()->json([
                'data' => $data,
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminEnrollmentController@index error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to load enrollments',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/enrollments
     * Manual enroll OR re-enroll (next year, repeat class)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'course_id'  => ['required', 'exists:courses,id'],
            'status'     => ['nullable', Rule::in(['enrolled', 'completed', 'dropped'])],
        ]);

        $status = $data['status'] ?? 'enrolled';
        $course = Course::findOrFail($data['course_id']);

        // Enrollment uniqueness: student_id + course_id
        $existing = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $data['course_id'])
            ->first();

        if ($existing) {
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
            'status' => ['required', Rule::in(['enrolled', 'completed', 'dropped'])],
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
