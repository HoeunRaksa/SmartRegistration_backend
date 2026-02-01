<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Course;
use App\Models\StudentClassGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminEnrollmentController extends Controller
{
    /**
     * GET /api/admin/enrollments
     * Query params (optional):
     * - department_id
     * - major_id
     * - class_group_id            ✅ filter students by pivot student_class_groups
     * - scg_academic_year         ✅ required when class_group_id provided
     * - scg_semester              ✅ required when class_group_id provided
     * - course_id
     * - status (enrolled|completed|dropped)
     * - academic_year (course academic_year)
     * - semester     (course semester)
     * - q (search student code/name/email/phone/address + major/subject/class/teacher)
     * - per_page (max 200)
     */
    public function index(Request $request)
    {
        try {
            $departmentId   = $request->query('department_id');
            $majorId        = $request->query('major_id');
            $classGroupId   = $request->query('class_group_id');     // ✅ NEW
            $scgYear        = $request->query('scg_academic_year');  // ✅ NEW
            $scgSemester    = $request->query('scg_semester');       // ✅ NEW

            $courseId       = $request->query('course_id');
            $status         = $request->query('status');
            $academicYear   = $request->query('academic_year');
            $semester       = $request->query('semester');
            $q              = trim((string) $request->query('q', ''));

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

            // ✅ NEW: filter enrollments by "student is in class_group for (year, semester)" from pivot student_class_groups
            if (!empty($classGroupId) || !empty($scgYear) || !empty($scgSemester)) {
                if (empty($classGroupId) || empty($scgYear) || empty($scgSemester)) {
                    return response()->json([
                        'message' => 'class_group_id, scg_academic_year, scg_semester are required together.',
                    ], 422);
                }

                $studentIdsQ = StudentClassGroup::query()
                    ->where('class_group_id', $classGroupId)
                    ->where('academic_year', $scgYear)
                    ->where('semester', (int) $scgSemester)
                    ->select('student_id');

                $enrollmentsQ->whereIn('student_id', $studentIdsQ);
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

            $paginator = $enrollmentsQ->orderByDesc('id')->paginate($perPage);

            $data = collect($paginator->items())->map(function ($e) {
                $student = $e->student;
                $course  = $e->course;

                $studentName = $student?->full_name_en ?: ($student?->full_name_kh ?: null);

                // ✅ SAME profile url logic
                $profileUrl = null;
                if ($student?->user && $student->user->profile_picture_path) {
                    $profileUrl = url('uploads/profiles/' . basename($student->user->profile_picture_path));
                }

                $regMajorName = $student?->registration?->major?->major_name;

                return [
                    'id' => $e->id,

                    'student_id'    => $e->student_id,
                    'student_code'  => $student?->student_code,
                    'student_name'  => $studentName,
                    'department_id' => $student?->department_id,

                    'email'   => $student?->user?->email,
                    'phone'   => $student?->phone_number,
                    'address' => $student?->address,
                    'profile_picture_url' => $profileUrl,

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
     * EASY (supports single or bulk):
     * - student_id (single) OR student_ids (array)
     * - course_id (this course already contains academic_year + semester => use next-semester course_id to enroll next semester)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id'  => ['nullable', 'exists:students,id'],
            'student_ids' => ['nullable', 'array'],
            'student_ids.*' => ['required_with:student_ids', 'exists:students,id'],

            'course_id'   => ['required', 'exists:courses,id'],

            'status'      => ['nullable', Rule::in(['enrolled', 'completed', 'dropped'])],
        ]);

        $status = $data['status'] ?? 'enrolled';

        // course_id decides the target academic_year + semester (next semester = pass the next semester course_id)
        $course = Course::select(['id', 'academic_year', 'semester'])->findOrFail($data['course_id']);

        // normalize to array
        $studentIds = [];
        if (!empty($data['student_ids']) && is_array($data['student_ids'])) {
            $studentIds = array_values(array_unique(array_map('intval', $data['student_ids'])));
        } elseif (!empty($data['student_id'])) {
            $studentIds = [(int) $data['student_id']];
        }

        if (count($studentIds) === 0) {
            return response()->json([
                'message' => 'student_id or student_ids is required.',
            ], 422);
        }

        $created = [];
        $skipped = [];

        try {
            DB::beginTransaction();

            // pre-check duplicates for this course to avoid N queries
            $existingIds = CourseEnrollment::query()
                ->where('course_id', $course->id)
                ->whereIn('student_id', $studentIds)
                ->pluck('student_id')
                ->map(fn ($v) => (int) $v)
                ->all();

            $existingSet = array_flip($existingIds);

            foreach ($studentIds as $sid) {
                if (isset($existingSet[$sid])) {
                    $skipped[] = [
                        'student_id' => $sid,
                        'reason' => 'already_enrolled_in_this_course',
                    ];
                    continue;
                }

                $enrollment = CourseEnrollment::create([
                    'student_id'  => $sid,
                    'course_id'   => $course->id,
                    'status'      => $status,
                    'progress'    => $status === 'enrolled' ? 0 : null,
                    'enrolled_at' => $status === 'enrolled' ? now() : null,
                    'dropped_at'  => $status === 'dropped' ? now() : null,
                ]);

                $created[] = $enrollment->id;
            }

            DB::commit();

            return response()->json([
                'message' => 'Enrollments processed',
                'target' => [
                    'course_id' => $course->id,
                    'academic_year' => $course->academic_year,
                    'semester' => $course->semester,
                    'status' => $status,
                ],
                'result' => [
                    'requested' => count($studentIds),
                    'created_count' => count($created),
                    'skipped_count' => count($skipped),
                    'created_enrollment_ids' => $created,
                    'skipped' => $skipped,
                ],
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminEnrollmentController@store error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'message' => 'Failed to create enrollments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/admin/enrollments/{id}/status
     */
    public function updateStatus(Request $request, $id)
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
        ]);

        $status = strtolower(trim((string) $data['status']));
        if (!in_array($status, ['enrolled', 'completed', 'dropped'], true)) {
            return response()->json([
                'message' => 'Invalid status value.',
            ], 422);
        }

        try {
            $enrollment = is_numeric($id) ? CourseEnrollment::find((int) $id) : null;

            if (!$enrollment) {
                $studentId = $request->input('student_id');
                $courseId = $request->input('course_id');
                if ($studentId && $courseId) {
                    $enrollment = CourseEnrollment::where('student_id', $studentId)
                        ->where('course_id', $courseId)
                        ->first();
                }
            }

            if (!$enrollment) {
                return response()->json([
                    'message' => 'Enrollment not found.',
                ], 404);
            }

            $update = ['status' => $status];

            if ($status === 'enrolled') {
                $update['enrolled_at'] = now();
                $update['dropped_at']  = null;
                $update['progress']    = 0;
            }

            if ($status === 'completed') {
                $update['progress'] = 100;
                $update['dropped_at'] = null;
            }

            if ($status === 'dropped') {
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
