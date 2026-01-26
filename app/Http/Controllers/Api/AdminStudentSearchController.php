<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentClassGroup;
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

            // ✅ FILTER 3: Class Group + Academic Year + Semester (via pivot table)
            // These filters must work together through student_class_groups
            if (!empty($classGroupId) || !empty($academicYear) || !empty($semester)) {
                // Use a subquery approach for better performance and accuracy
                $pivotQuery = StudentClassGroup::query()->select('student_id');

                if (!empty($classGroupId)) {
                    $pivotQuery->where('class_group_id', $classGroupId);
                }
                if (!empty($academicYear)) {
                    $pivotQuery->where('academic_year', $academicYear);
                }
                if (!empty($semester)) {
                    $pivotQuery->where('semester', (int)$semester);
                }

                $studentsQ->whereIn('students.id', $pivotQuery);
            }

            // ✅ FILTER 4: Shift (from class_groups table via pivot)
            if (!empty($shift)) {
                $studentsQ->whereHas('classGroups', function ($cg) use ($shift, $academicYear, $semester) {
                    $cg->where('class_groups.shift', $shift);
                    
                    // If academic_year and semester are also provided, apply them to ensure consistency
                    if (!empty($academicYear)) {
                        $cg->where('student_class_groups.academic_year', $academicYear);
                    }
                    if (!empty($semester)) {
                        $cg->where('student_class_groups.semester', (int)$semester);
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