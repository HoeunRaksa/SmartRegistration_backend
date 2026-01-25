<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminStudentSearchController extends Controller
{
    public function search(Request $request)
    {
        try {
            // Get filter parameters
            $departmentId = $request->query('department_id');
            $majorId      = $request->query('major_id');
            $academicYear = $request->query('academic_year');
            $semester     = $request->query('semester');
            $classGroupId = $request->query('class_group_id');
            $shift        = $request->query('shift');
            $q            = trim((string) $request->query('q', ''));

            $perPage = (int) $request->query('per_page', 30);
            if ($perPage <= 0) $perPage = 30;
            if ($perPage > 200) $perPage = 200;

            $studentsQ = Student::query()
                ->select([
                    'students.id',
                    'students.user_id',
                    'students.registration_id',
                    'students.student_code',
                    'students.full_name_en',
                    'students.full_name_kh',
                    'students.department_id',
                    'students.phone_number',
                    'students.address',
                ])
                ->with([
                    'user:id,email,profile_picture_path',
                    'registration:id,major_id,department_id',
                    'registration.major:id,major_name,department_id',
                ]);

            // ✅ FILTER 1: Department (from students table)
            if (!empty($departmentId)) {
                $studentsQ->where('students.department_id', $departmentId);
            }

            // ✅ FILTER 2: Major (from registration)
            if (!empty($majorId)) {
                $studentsQ->whereHas('registration', function ($rq) use ($majorId) {
                    $rq->where('major_id', $majorId);
                });
            }

            // ✅ FILTER 3: Academic Year + Semester + Class Group + Shift
            // All these come from student_class_groups pivot or class_groups table
            if (!empty($academicYear) || !empty($semester) || !empty($classGroupId) || !empty($shift)) {
                $studentsQ->whereHas('classGroups', function ($cg) use ($academicYear, $semester, $classGroupId, $shift) {
                    // Filter by pivot table columns using direct table reference
                    if (!empty($academicYear)) {
                        $cg->where('student_class_groups.academic_year', $academicYear);
                    }
                    if (!empty($semester)) {
                        $cg->where('student_class_groups.semester', (int)$semester);
                    }

                    // Filter by class_groups table columns
                    if (!empty($classGroupId)) {
                        $cg->where('class_groups.id', $classGroupId);
                    }
                    if (!empty($shift)) {
                        $cg->where('class_groups.shift', $shift);
                    }
                });
            }

            // ✅ SEARCH: Student code, name, phone, address, email
            if ($q !== '') {
                $studentsQ->where(function ($root) use ($q) {
                    $root->where('students.student_code', 'like', "%{$q}%")
                        ->orWhere('students.full_name_en', 'like', "%{$q}%")
                        ->orWhere('students.full_name_kh', 'like', "%{$q}%")
                        ->orWhere('students.phone_number', 'like', "%{$q}%")
                        ->orWhere('students.address', 'like', "%{$q}%")
                        ->orWhereHas('user', function ($uq) use ($q) {
                            $uq->where('email', 'like', "%{$q}%");
                        });
                });
            }

            $paginator = $studentsQ->orderByDesc('students.id')->paginate($perPage);

            $data = collect($paginator->items())->map(function ($s) {
                $profileUrl = null;
                if ($s->user && $s->user->profile_picture_path) {
                    $profileUrl = url('uploads/profiles/' . basename($s->user->profile_picture_path));
                }

                return [
                    'id' => $s->id,
                    'student_code' => $s->student_code,
                    'student_name' => $s->full_name_en ?: ($s->full_name_kh ?: null),
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
                        'major' => [
                            'major_name' => $s->registration?->major?->major_name,
                        ],
                    ],
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
            Log::error('AdminStudentController@search error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to search students',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
