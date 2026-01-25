<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;

class AdminStudentSearchController extends Controller
{
    /**
     * GET /api/students/search
     * Query:
     * - department_id
     * - major_id   (via registration)
     * - class_group_id (via student_class_groups)
     * - academic_year
     * - semester
     * - q (code/name/email/phone)
     * - page, per_page
     */
    public function search(Request $request)
    {
        $departmentId = $request->query('department_id');
        $majorId      = $request->query('major_id');
        $classGroupId = $request->query('class_group_id');
        $academicYear = $request->query('academic_year');
        $semester     = $request->query('semester');
        $q            = trim((string) $request->query('q', ''));

        $perPage = (int) $request->query('per_page', 30);
        if ($perPage <= 0) $perPage = 30;
        if ($perPage > 100) $perPage = 100;

        $studentsQ = Student::query()
            ->select([
                'id',
                'user_id',
                'registration_id',
                'student_code',
                'full_name_en',
                'full_name_kh',
                'department_id',
                'phone_number',
                'address',
            ])
            ->with([
                'user:id,email,profile_picture_path',
                'registration:id,major_id,department_id',
                'registration.major:id,major_name,department_id',
            ]);

        // department filter
        if (!empty($departmentId)) {
            $studentsQ->where('department_id', $departmentId);
        }

        // major filter via registration
        if (!empty($majorId)) {
            $studentsQ->whereHas('registration', function ($rq) use ($majorId) {
                $rq->where('major_id', $majorId);
            });
        }

        // class group filter via pivot student_class_groups
        if (!empty($classGroupId)) {
            $studentsQ->whereHas('classGroups', function ($cgq) use ($classGroupId, $academicYear, $semester) {
                $cgq->where('class_groups.id', $classGroupId);

                if (!empty($academicYear)) {
                    $cgq->wherePivot('academic_year', $academicYear);
                }
                if (!empty($semester)) {
                    $cgq->wherePivot('semester', (int) $semester);
                }
            });
        }

        // text search
        if ($q !== '') {
            $studentsQ->where(function ($root) use ($q) {
                $root->where('student_code', 'like', "%{$q}%")
                    ->orWhere('full_name_en', 'like', "%{$q}%")
                    ->orWhere('full_name_kh', 'like', "%{$q}%")
                    ->orWhere('phone_number', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%")
                    ->orWhereHas('user', function ($uq) use ($q) {
                        $uq->where('email', 'like', "%{$q}%");
                    });
            });
        }

        $paginator = $studentsQ->orderByDesc('id')->paginate($perPage);

        $data = collect($paginator->items())->map(function ($s) {
            $profileUrl = null;
            if ($s->user && $s->user->profile_picture_path) {
                $profileUrl = url('uploads/profiles/' . basename($s->user->profile_picture_path));
            }

            return [
                'id' => $s->id,
                'student_code' => $s->student_code,
                'full_name_en' => $s->full_name_en,
                'full_name_kh' => $s->full_name_kh,
                'department_id' => $s->department_id,
                'phone_number' => $s->phone_number,
                'address' => $s->address,
                'email' => $s->user?->email,
                'profile_picture_url' => $profileUrl,

                'registration' => [
                    'major_id' => $s->registration?->major_id,
                    'department_id' => $s->registration?->department_id,
                    'major_name' => $s->registration?->major?->major_name,
                ],
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 200);
    }
}
