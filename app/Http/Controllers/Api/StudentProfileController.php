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
                ->with(['user', 'department', 'registration.major'])
                ->first();
 
            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                ], 404);
            }
 
            // ✅ FIX: Ensure profile_picture_url is properly calculated
            $profilePictureUrl = null;
            if ($student->user && $student->user->profile_picture_path) {
                $profilePictureUrl = url($student->user->profile_picture_path);
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
                'profile_picture_url' => $profilePictureUrl, // ✅ Fixed
 
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
                    'major' => [
                        'id' => $student->registration?->major?->id,
                        'name' => $student->registration?->major?->major_name,
                        'code' => $student->registration?->major?->major_code,
                    ],
                ],
            ];
 
            return response()->json([
                'success' => true,
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
            $student = Student::where('user_id', $user->id)->with('user')->first();
 
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
 
            // Update allowed fields on Student model
            $student->phone_number = $request->input('phone_number', $student->phone_number);
            $student->address = $request->input('address', $student->address);
            $student->parent_name = $request->input('parent_name', $student->parent_name);
            $student->parent_phone = $request->input('parent_phone', $student->parent_phone);
 
            // ✅ FIX: Handle profile picture upload - store on User model, not Student
            if ($request->hasFile('profile_picture')) {
                if (!$student->user) {
                    return response()->json([
                        'error' => true,
                        'message' => 'User account not found',
                    ], 404);
                }
 
                // Delete old picture if exists
                if ($student->user->profile_picture_path) {
                    // Remove 'public/' prefix if present for storage deletion
                    $oldPath = str_replace('public/', '', $student->user->profile_picture_path);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }
 
                // Store new picture
                $file = $request->file('profile_picture');
                $filename = 'profile_' . $student->user->id . '_' . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('profiles', $filename, 'public');
 
                // Store as 'storage/profiles/filename.jpg' format for public access
                $student->user->profile_picture_path = 'storage/' . $path;
                $student->user->save();
            }
 
            $student->save();
 
            // ✅ FIX: Return properly calculated profile_picture_url
            $profilePictureUrl = null;
            if ($student->user && $student->user->profile_picture_path) {
                $profilePictureUrl = url($student->user->profile_picture_path);
            }
 
            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'phone_number' => $student->phone_number,
                    'address' => $student->address,
                    'parent_name' => $student->parent_name,
                    'parent_phone' => $student->parent_phone,
                    'profile_picture_url' => $profilePictureUrl, // ✅ Fixed
                ]
            ]);
 
        } catch (\Exception $e) {
            Log::error('Error updating student profile: ' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'error' => true,
                'message' => 'Failed to update profile',
            ], 500);
        }
    }
}