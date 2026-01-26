<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
class StudentProfileController extends Controller
{
    /**
     * Get student profile
     * GET /api/student/profile
     */
    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)
                ->with(['user', 'department', 'registration'])
                ->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                ], 404);
            }

            $profile = [
                'id' => $student->id,
                'student_code' => $student->student_code,
                'full_name_kh' => $student->full_name_kh,
                'full_name_en' => $student->full_name_en,
                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'nationality' => $student->nationality,
                'phone_number' => $student->phone_number,
                'address' => $student->address,
                'generation' => $student->generation,
                'parent_name' => $student->parent_name,
                'parent_phone' => $student->parent_phone,
                'profile_picture_url' => $student->profile_picture_url,
                
                // User information
                'email' => $user->email,
                'name' => $user->name,
                'role' => $user->role,
                
                // Department information
                'department' => [
                    'id' => $student->department?->id,
                    'name' => $student->department?->department_name,
                    'code' => $student->department?->department_code,
                ],
                
                // Registration information
                'registration' => [
                    'id' => $student->registration?->id,
                    'academic_year' => $student->registration?->academic_year,
                    'semester' => $student->registration?->semester,
                    'status' => $student->registration?->status,
                ],
            ];

            return response()->json([
                'data' => $profile
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching student profile: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch profile',
            ], 500);
        }
    }

    /**
     * Update student profile
     * PUT /api/student/profile
     */
    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:500',
                'parent_name' => 'nullable|string|max:255',
                'parent_phone' => 'nullable|string|max:20',
                'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Update allowed fields
            $student->phone_number = $request->input('phone_number', $student->phone_number);
            $student->address = $request->input('address', $student->address);
            $student->parent_name = $request->input('parent_name', $student->parent_name);
            $student->parent_phone = $request->input('parent_phone', $student->parent_phone);

            // Handle profile picture upload
            if ($request->hasFile('profile_picture')) {
                // Delete old picture if exists
                if ($student->profile_picture_path) {
                    Storage::disk('public')->delete($student->profile_picture_path);
                }

                // Store new picture
                $path = $request->file('profile_picture')->store('profile_pictures', 'public');
                $student->profile_picture_path = $path;
            }

            $student->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => [
                    'phone_number' => $student->phone_number,
                    'address' => $student->address,
                    'parent_name' => $student->parent_name,
                    'parent_phone' => $student->parent_phone,
                    'profile_picture_url' => $student->profile_picture_url,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating student profile: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to update profile',
            ], 500);
        }
    }
}