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
            $departmentId = $request->query('department_id');
            $majorId      = $request->query('major_id');
            $academicYear = $request->query('academic_year');
            $semester     = $request->query('semester');
            $q            = trim((string) $request->query('q', ''));

            $perPage = (int) $request->query('per_page', 30);
            if ($perPage <= 0) $perPage = 30;
            if ($perPage > 200) $perPage = 200;

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

            // ✅ department filter (students table)
            if (!empty($departmentId)) {
                $studentsQ->where('department_id', $departmentId);
            }

            // ✅ major filter (registration)
            if (!empty($majorId)) {
                $studentsQ->whereHas('registration', function ($rq) use ($majorId) {
                    $rq->where('major_id', $majorId);
                });
            }

            // ✅ FIXED: academic_year + semester filter (pivot student_class_groups)
            // student_class_groups columns: student_id, class_group_id, academic_year, semester
            // The issue was using ->where() instead of ->wherePivot() for pivot table columns
            if (!empty($academicYear) || !empty($semester)) {
                $studentsQ->whereHas('classGroups', function ($cg) use ($academicYear, $semester) {
                    // Use wherePivot for columns in the pivot table (student_class_groups)
                    if (!empty($academicYear)) {
                        $cg->wherePivot('academic_year', $academicYear);
                    }
                    if (!empty($semester)) {
                        $cg->wherePivot('semester', (int)$semester);
                    }
                });
            }

            // ✅ search
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