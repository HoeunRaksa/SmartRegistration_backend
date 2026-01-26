<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Grade;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;

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

            $student = Student::with([
                    'department',
                    'registration.major',
                    'user',
                ])
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Calculate GPA
            $gradePoints = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->pluck('grade_point');
            $gpa = $gradePoints->count() > 0 ? round($gradePoints->avg(), 2) : null;

            // Calculate credits earned
            $creditsEarned = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->where('grade_point', '>=', 1.0)
                ->with('course.majorSubject.subject')
                ->get()
                ->sum(fn($g) => $g->course?->majorSubject?->subject?->credits ?? 0);

            // Get enrolled courses count
            $enrolledCourses = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->count();

            $data = [
                'id' => $student->id,
                'student_code' => $student->student_code,

                // Names
                'full_name_kh' => $student->full_name_kh,
                'full_name_en' => $student->full_name_en,
                'name' => $student->full_name_en ?: $student->full_name_kh ?: ($user->name ?? null),

                // Contact
                'email' => $user->email ?? null,
                'phone' => $student->phone_number,
                'phone_number' => $student->phone_number,
                'address' => $student->address,

                // Personal info
                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'nationality' => $student->nationality,
                'generation' => $student->generation,

                // Parent info
                'parent_name' => $student->parent_name,
                'parent_phone' => $student->parent_phone,

                // Profile picture
                'profile_picture_url' => $student->profile_picture_url,

                // Academic info
                'department_id' => $student->department_id,
                'department' => $student->department?->department_name ?? $student->department?->name ?? null,
                'major' => $student->registration?->major?->major_name ?? null,
                'major_id' => $student->registration?->major_id ?? null,

                // Registration
                'registration_id' => $student->registration_id,
                'registration_date' => $student->registration?->created_at?->format('Y-m-d'),

                // Academic stats
                'year' => $this->calculateYear($student),
                'semester' => $this->getCurrentSemester(),
                'academic_status' => 'Active',
                'current_gpa' => $gpa,
                'cumulative_gpa' => $gpa,
                'credits_earned' => $creditsEarned,
                'enrolled_courses' => $enrolledCourses,
            ];

            return response()->json(['data' => $data], 200);
        } catch (\Throwable $e) {
            Log::error('StudentProfileController@getProfile error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load student profile'], 500);
        }
    }

    /**
     * Update student profile
     * PUT /api/student/profile
     */
    public function updateProfile(Request $request)
    {
        $request->validate([
            'phone_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'parent_name' => 'nullable|string|max:255',
            'parent_phone' => 'nullable|string|max:20',
        ]);

        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->firstOrFail();

            $student->update($request->only([
                'phone_number',
                'address',
                'parent_name',
                'parent_phone',
            ]));

            return response()->json([
                'message' => 'Profile updated successfully',
                'data' => $student->fresh()
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentProfileController@updateProfile error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update profile'], 500);
        }
    }

    /**
     * Upload profile picture
     * POST /api/student/profile/picture
     */
    public function uploadProfilePicture(Request $request)
    {
        $request->validate([
            'profile_picture' => 'required|image|max:2048', // 2MB max
        ]);

        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->firstOrFail();

            // Delete old picture if exists
            if ($student->profile_picture && Storage::disk('public')->exists($student->profile_picture)) {
                Storage::disk('public')->delete($student->profile_picture);
            }

            // Store new picture
            $path = $request->file('profile_picture')->store('profile_pictures', 'public');

            $student->update(['profile_picture' => $path]);

            return response()->json([
                'message' => 'Profile picture uploaded successfully',
                'data' => ['profile_picture_url' => $student->fresh()->profile_picture_url]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentProfileController@uploadProfilePicture error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to upload profile picture'], 500);
        }
    }

    /**
     * Change password
     * PUT /api/student/profile/password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = $request->user();

            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json(['message' => 'Current password is incorrect'], 422);
            }

            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return response()->json(['message' => 'Password changed successfully'], 200);
        } catch (\Throwable $e) {
            Log::error('StudentProfileController@changePassword error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to change password'], 500);
        }
    }

    /**
     * Calculate student year based on generation
     */
    private function calculateYear($student): ?int
    {
        if (!$student->generation) {
            return null;
        }
        $currentYear = (int) date('Y');
        $generationYear = (int) $student->generation;
        return max(1, min(4, $currentYear - $generationYear + 1));
    }

    /**
     * Get current semester (1 or 2 based on month)
     */
    private function getCurrentSemester(): int
    {
        $month = (int) date('n');
        return ($month >= 9 || $month <= 1) ? 1 : 2;
    }
}
