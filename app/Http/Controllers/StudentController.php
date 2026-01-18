<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

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
    public function store(Request $request)
    {
        $validated = $request->validate([
            'registration_id' => 'required|exists:registrations,id',
            'user_id' => 'required|exists:users,id',
            'department_id' => 'required|exists:departments,id',

            'full_name_kh' => 'required|string|max:255',
            'full_name_en' => 'required|string|max:255',
            'date_of_birth' => 'required|date',
            'gender' => 'required|string',

            'nationality' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'generation' => 'nullable|string|max:50',

            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',

            'profile_picture_path' => 'nullable|string',
        ]);

        $student = Student::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Student created successfully',
            'data' => $student
        ], 201);
    }

    /**
     * PUT /api/students/{id}
     */
    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);

        $validated = $request->validate([
            'department_id' => 'sometimes|exists:departments,id',

            'full_name_kh' => 'sometimes|string|max:255',
            'full_name_en' => 'sometimes|string|max:255',
            'date_of_birth' => 'sometimes|date',
            'gender' => 'sometimes|string',

            'nationality' => 'nullable|string|max:100',
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'generation' => 'nullable|string|max:50',

            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',

            'profile_picture_path' => 'nullable|string',
        ]);

        $student->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Student updated successfully',
            'data' => $student
        ]);
    }

    /**
     * DELETE /api/students/{id}
     */
    public function destroy($id)
    {
        $student = Student::findOrFail($id);
        $student->delete();

        return response()->json([
            'success' => true,
            'message' => 'Student deleted successfully'
        ]);
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

            // Optionally revoke all existing tokens to force re-login
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