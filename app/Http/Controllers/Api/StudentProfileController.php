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

            $student = Student::with(['department', 'user'])
                ->where('user_id', $user->id)
                ->firstOrFail();

            // Normalize fields for frontend to prevent blank UI
            return response()->json([
                'data' => [
                    'id' => $student->id,
                    'student_code' => $student->student_code,

                    // Use Khmer name if exists, else English, else user's name
                    'name' => $student->full_name_kh ?: ($student->full_name_en ?: ($student->user->name ?? '')),

                    'full_name_kh' => $student->full_name_kh,
                    'full_name_en' => $student->full_name_en,

                    'email' => $student->user->email ?? null,

                    'phone' => $student->phone_number,          // frontend key
                    'phone_number' => $student->phone_number,   // keep original too

                    'date_of_birth' => $student->date_of_birth,
                    'gender' => $student->gender,
                    'nationality' => $student->nationality,
                    'address' => $student->address,

                    // department relationship
                    'department_id' => $student->department_id,
                    'department' => $student->department?->department_name
                        ?? $student->department?->name
                        ?? null,

                    // registration related
                    'registration_id' => $student->registration_id,
                    'generation' => $student->generation,

                    // parent info (emergency contact in your UI)
                    'emergency_contact_name' => $student->parent_name,
                    'emergency_contact_phone' => $student->parent_phone,
                    'emergency_contact_relation' => 'Parent',

                    // profile picture accessor from your model (appends)
                    'profile_picture_url' => $student->profile_picture_url,
                    'profile_picture_path' => $student->profile_picture_path,
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('StudentProfileController@getProfile error: '.$e->getMessage());
            return response()->json(['message' => 'Failed to load profile'], 500);
        }
    }
}
