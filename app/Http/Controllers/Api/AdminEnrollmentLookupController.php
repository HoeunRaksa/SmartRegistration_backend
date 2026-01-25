<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentClassGroup;
use Illuminate\Http\Request;

class AdminEnrollmentLookupController extends Controller
{
    /* =========================================================
     * GET /api/admin/enrollment-lookup/class-groups
     * Filters: department_id (via major), major_id, academic_year, semester, shift, q
     * ========================================================= */
    public function classGroups(Request $request)
    {
        $majorId = $request->query('major_id');
        $year    = $request->query('academic_year');
        $sem     = $request->query('semester');
        $shift   = $request->query('shift');
        $q       = trim((string) $request->query('q', ''));

        $perPage = (int) $request->query('per_page', 20);
        if ($perPage <= 0) $perPage = 20;
        if ($perPage > 100) $perPage = 100;

        $query = ClassGroup::query()
            ->select(['id','class_name','major_id','academic_year','semester','shift','capacity'])
            ->with(['major:id,major_name,department_id']);

        if (!empty($majorId)) $query->where('major_id', $majorId);
        if (!empty($year))    $query->where('academic_year', $year);
        if (!empty($sem))     $query->where('semester', (int)$sem);
        if (!empty($shift))   $query->where('shift', $shift);

        if ($q !== '') {
            $query->where('class_name', 'like', "%{$q}%");
        }

        $p = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($p->items())->map(function ($cg) {
                return [
                    'id' => $cg->id,
                    'class_name' => $cg->class_name,
                    'major_id' => $cg->major_id,
                    'major_name' => $cg->major?->major_name,
                    'department_id' => $cg->major?->department_id,
                    'academic_year' => $cg->academic_year,
                    'semester' => $cg->semester,
                    'shift' => $cg->shift,
                    'capacity' => $cg->capacity,
                ];
            }),
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ]
        ]);
    }

    /* =========================================================
     * GET /api/admin/enrollment-lookup/courses
     * Filters: class_group_id, academic_year, semester, q
     * ========================================================= */
    public function courses(Request $request)
    {
        $classGroupId = $request->query('class_group_id');
        $year         = $request->query('academic_year');
        $sem          = $request->query('semester');
        $q            = trim((string) $request->query('q', ''));

        $perPage = (int) $request->query('per_page', 50);
        if ($perPage <= 0) $perPage = 50;
        if ($perPage > 200) $perPage = 200;

        $query = Course::query()
            ->select(['id','display_name','course_name','academic_year','semester','class_group_id'])
            ->with(['classGroup:id,class_name']);

        if (!empty($classGroupId)) $query->where('class_group_id', $classGroupId);
        if (!empty($year))         $query->where('academic_year', $year);
        if (!empty($sem))          $query->where('semester', (int)$sem);

        if ($q !== '') {
            $query->where(function($w) use ($q){
                $w->where('display_name', 'like', "%{$q}%")
                  ->orWhere('course_name', 'like', "%{$q}%");
            });
        }

        $p = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => collect($p->items())->map(fn($c) => [
                'id' => $c->id,
                'label' => $c->display_name ?: ($c->course_name ?: ("Course #".$c->id)),
                'academic_year' => $c->academic_year,
                'semester' => $c->semester,
                'class_group_id' => $c->class_group_id,
                'class_name' => $c->classGroup?->class_name,
            ]),
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ]
        ]);
    }

    /* =========================================================
     * GET /api/admin/enrollment-lookup/students
     * Filters:
     * - department_id (students table)
     * - major_id (via students.registration.major_id)
     * - class_group_id + academic_year + semester (pivot student_class_groups)
     * - q (student_code/name/email)
     * - course_id (optional) => already_enrolled boolean
     * ========================================================= */
    public function students(Request $request)
    {
        $departmentId = $request->query('department_id');
        $majorId      = $request->query('major_id');

        $classGroupId = $request->query('class_group_id');
        $year         = $request->query('academic_year');
        $sem          = $request->query('semester');

        $courseId     = $request->query('course_id'); // optional for already_enrolled
        $q            = trim((string) $request->query('q', ''));

        $perPage = (int) $request->query('per_page', 20);
        if ($perPage <= 0) $perPage = 20;
        if ($perPage > 100) $perPage = 100;

        $query = Student::query()
            ->select([
                'students.id',
                'students.student_code',
                'students.full_name_en',
                'students.full_name_kh',
                'students.department_id',
                'students.user_id',
            ])
            ->with(['user:id,email,profile_picture_path', 'registration:id,major_id']);

        // department filter (fast)
        if (!empty($departmentId)) {
            $query->where('students.department_id', $departmentId);
        }

        // major filter via registration
        if (!empty($majorId)) {
            $query->whereHas('registration', fn($r) => $r->where('major_id', $majorId));
        }

        // ✅ class group filter via pivot student_class_groups (your model)
        if (!empty($classGroupId) || !empty($year) || !empty($sem)) {
            if (empty($classGroupId) || empty($year) || empty($sem)) {
                return response()->json([
                    'message' => 'class_group_id, academic_year, semester are required together for class group filtering.'
                ], 422);
            }

            $pivotIds = StudentClassGroup::query()
                ->where('class_group_id', $classGroupId)
                ->where('academic_year', $year)
                ->where('semester', (int)$sem)
                ->select('student_id');

            $query->whereIn('students.id', $pivotIds);
        }

        // search
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('students.student_code', 'like', "%{$q}%")
                  ->orWhere('students.full_name_en', 'like', "%{$q}%")
                  ->orWhere('students.full_name_kh', 'like', "%{$q}%")
                  ->orWhereHas('user', fn($u) => $u->where('email', 'like', "%{$q}%"));
            });
        }

        // ✅ show already enrolled for selected course
        if (!empty($courseId)) {
            $cid = (int) $courseId;
            $query->selectRaw(
                "EXISTS(
                    SELECT 1 FROM course_enrollments ce
                    WHERE ce.student_id = students.id
                      AND ce.course_id = ?
                ) AS already_enrolled",
                [$cid]
            );
        }

        $p = $query->orderByDesc('students.id')->paginate($perPage);

        $data = collect($p->items())->map(function ($s) {
            $profileUrl = null;
            if ($s->user?->profile_picture_path) {
                $profileUrl = url('uploads/profiles/' . basename($s->user->profile_picture_path));
            }
            return [
                'id' => $s->id,
                'student_code' => $s->student_code,
                'full_name_en' => $s->full_name_en,
                'full_name_kh' => $s->full_name_kh,
                'department_id' => $s->department_id,
                'email' => $s->user?->email,
                'profile_picture_url' => $profileUrl,
                'major_id' => $s->registration?->major_id,
                'already_enrolled' => isset($s->already_enrolled) ? (bool)$s->already_enrolled : false,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'total' => $p->total(),
                'last_page' => $p->lastPage(),
            ],
        ]);
    }
}
