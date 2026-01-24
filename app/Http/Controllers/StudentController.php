<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\CourseEnrollment;

class StudentController extends Controller
{
    /**
     * GET /api/students
     * Admin / Staff only
     */
    public function index()
    {
        $students = Student::with(['department', 'user', 'registration'])->get();

        $students->transform(function ($student) {
            // ✅ correct + safe (profile_picture_path already "uploads/profiles/xxx.jpg")
            if ($student->user && $student->user->profile_picture_path) {
                $student->profile_picture_url = url($student->user->profile_picture_path);
            } else {
                $student->profile_picture_url = null;
            }
            return $student;
        });

        return response()->json([
            'success' => true,
            'data' => $students
        ]);
    }

    /**
     * GET /api/students/{id}
     */
    public function show($id)
    {
        $student = Student::with(['department', 'user', 'registration'])->findOrFail($id);

        // ✅ correct + safe
        if ($student->user && $student->user->profile_picture_path) {
            $student->profile_picture_url = url($student->user->profile_picture_path);
        } else {
            $student->profile_picture_url = null;
        }

        return response()->json([
            'success' => true,
            'data' => $student
        ]);
    }

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

                if (array_key_exists('phone_number', $data))  $regUpdate['phone_number']  = $data['phone_number'];
                if (array_key_exists('address', $data))       $regUpdate['address']       = $data['address'];
                if (array_key_exists('department_id', $data)) $regUpdate['department_id'] = $data['department_id'];
                if (array_key_exists('date_of_birth', $data)) $regUpdate['date_of_birth'] = $data['date_of_birth'];
                if (array_key_exists('gender', $data))        $regUpdate['gender']        = $data['gender'];
                if (array_key_exists('full_name_en', $data))  $regUpdate['full_name_en']  = $data['full_name_en'];
                if (array_key_exists('full_name_kh', $data))  $regUpdate['full_name_kh']  = $data['full_name_kh'];

                if (!empty($regUpdate)) {
                    $student->registration->update($regUpdate);
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

            // ✅ ensure response includes profile_picture_url too
            $fresh = $student->fresh(['department', 'user', 'registration']);
            if ($fresh->user && $fresh->user->profile_picture_path) {
                $fresh->profile_picture_url = url($fresh->user->profile_picture_path);
            } else {
                $fresh->profile_picture_url = null;
            }

            return response()->json([
                'success' => true,
                'message' => 'Student updated successfully',
                'data' => $fresh,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/students/{id}
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $student = Student::findOrFail($id);

            CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'ENROLLED')
                ->update([
                    'status' => 'DROPPED',
                    'dropped_at' => now(),
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Student enrollments dropped successfully'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to drop enrollments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/students/{id}/reset-password
     * Reset student password (Admin/Staff only)
     */
    public function resetPassword(Request $request, $id)
    {
        $validated = $request->validate([
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        DB::beginTransaction();

        try {
            $student = Student::with('user')->findOrFail($id);

            if (!$student->user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student has no associated user account'
                ], 404);
            }

            $user = $student->user;
            $user->password = Hash::make($validated['new_password']);
            $user->save();

            $user->tokens()->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully. Student must login with new password.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
