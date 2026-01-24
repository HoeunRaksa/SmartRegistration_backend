<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentProfileController extends Controller
{
    public function getProfile(Request $request)
    {
        try {
            $user = $request->user();

            $student = Student::with([
                    'department',
                    'registration',
                    'user',
                ])
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Build a clean response shape for frontend (avoid blanks)
            $data = [
                'id' => $student->id,
                'student_code' => $student->student_code,

                // Names from students table (your model has full_name_kh/full_name_en)
                'full_name_kh' => $student->full_name_kh,
                'full_name_en' => $student->full_name_en,

                // Keep a generic "name" for UI
                'name' => $student->full_name_en ?: $student->full_name_kh ?: ($student->user->name ?? null),

                // Email from users table
                'email' => $student->user->email ?? null,

                // Phone + address from students table
                'phone' => $student->phone_number,
                'phone_number' => $student->phone_number,
                'address' => $student->address,

                'date_of_birth' => $student->date_of_birth,
                'gender' => $student->gender,
                'nationality' => $student->nationality,

                'generation' => $student->generation,

                // Parent fields (your model uses parent_name/parent_phone)
                'parent_name' => $student->parent_name,
                'parent_phone' => $student->parent_phone,

                // profile picture accessor already appended
                'profile_picture_url' => $student->profile_picture_url,

                // Department relation
                'department_id' => $student->department_id,
                'department' => $student->department?->department_name ?? $student->department?->name ?? null,

                // Registration relation (some data comes from registration)
                'registration_id' => $student->registration_id,
                'registration' => $student->registration, // optional full object

                // If you have these columns later, keep them (avoid breaking UI)
                'major' => $student->registration?->major?->major_name
                    ?? $student->registration?->major_name
                    ?? null,

                // Optional placeholders (if your DB doesn’t have them yet)
                'year' => null,
                'semester' => null,
                'academic_status' => 'Active',

                // GPA/credits placeholders (real GPA should come from /student/grades/gpa)
                'current_gpa' => null,
                'cumulative_gpa' => null,
                'credits_earned' => 0,
            ];

            return response()->json(['data' => $data], 200);
        } catch (\Throwable $e) {
            Log::error('StudentProfileController@getProfile error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load student profile'], 500);
        }
    }
}
