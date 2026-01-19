<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    /**
     * GET /api/students
     * Admin / Staff only
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Student::with(['department', 'user', 'registration'])->get()
        ]);
    }

    /**
     * GET /api/students/{id}
     */
    public function show($id)
    {
        $student = Student::with(['department', 'user', 'registration'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $student
        ]);
    }

    /**
     * POST /api/students
     * Usually called AFTER registration approval
     */
    /**
     * PUT /api/students/{id}
     */
  public function update(Request $request, $id)
    {
        $student = Student::with(['user', 'registration'])->findOrFail($id);

        $data = Validator::make($request->all(), [
            // STUDENT (important personal)
            'department_id'   => ['sometimes', 'exists:departments,id'],
            'full_name_kh'    => ['sometimes', 'string', 'max:255'],
            'full_name_en'    => ['sometimes', 'string', 'max:255'],
            'date_of_birth'   => ['sometimes', 'date'],
            'gender'          => ['sometimes', 'string', 'max:20'],
            'nationality'     => ['nullable', 'string', 'max:100'],
            'phone_number'    => ['nullable', 'string', 'max:20'],
            'address'         => ['nullable', 'string', 'max:255'],
            'generation'      => ['nullable', 'string', 'max:50'],

            // UNIVERSITY fields (in REGISTRATIONS)
            'major_id'        => ['sometimes', 'exists:majors,id'],
            'shift'           => ['nullable', 'string', 'max:50'],
            'batch'           => ['nullable', 'string', 'max:50'],
            'academic_year'   => ['nullable', 'string', 'max:50'],

            // registration personal contact (optional)
            'personal_email'  => [
                'nullable',
                'email',
                'max:255',
                // ✅ avoid duplicate email in users table
                Rule::unique('users', 'email')->ignore(optional($student->user)->id),
            ],
            'current_address' => ['nullable', 'string', 'max:255'],
        ])->validate();

        DB::beginTransaction();
        try {
            // 1) update STUDENT table
            $studentFields = [
                'department_id',
                'full_name_kh',
                'full_name_en',
                'date_of_birth',
                'gender',
                'nationality',
                'phone_number',
                'address',
                'generation'
            ];

            $studentUpdate = array_intersect_key($data, array_flip($studentFields));
            if (!empty($studentUpdate)) {
                $student->update($studentUpdate);
            }

            // 2) update REGISTRATION table (university/contact)
            if ($student->registration) {
                $regUpdate = array_intersect_key($data, array_flip([
                    'major_id',
                    'shift',
                    'batch',
                    'academic_year',
                    'personal_email',
                    'current_address'
                ]));

                // keep registration phone/address same as student
                if (array_key_exists('phone_number', $data)) $regUpdate['phone_number'] = $data['phone_number'];
                if (array_key_exists('address', $data))      $regUpdate['address']      = $data['address'];
                if (array_key_exists('department_id', $data)) $regUpdate['department_id'] = $data['department_id'];
                if (array_key_exists('date_of_birth', $data)) $regUpdate['date_of_birth'] = $data['date_of_birth'];
                if (array_key_exists('gender', $data))        $regUpdate['gender']        = $data['gender'];
                if (array_key_exists('full_name_en', $data))  $regUpdate['full_name_en']  = $data['full_name_en'];
                if (array_key_exists('full_name_kh', $data))  $regUpdate['full_name_kh']  = $data['full_name_kh'];

                if (!empty($regUpdate)) {
                    $student->registration->update($regUpdate); // ✅ auto updated_at
                }
            }

            // 3) update USER table (name/email)
            if ($student->user) {
                $userUpdate = [];

                if (array_key_exists('full_name_en', $data)) {
                    $userUpdate['name'] = $data['full_name_en'];
                }

                if (array_key_exists('personal_email', $data) && $data['personal_email']) {
                    $userUpdate['email'] = $data['personal_email'];
                }

                if (!empty($userUpdate)) {
                    $student->user->update($userUpdate);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Student updated successfully',
                'data' => $student->fresh(['department', 'user', 'registration']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}