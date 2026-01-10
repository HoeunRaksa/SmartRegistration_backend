<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Registration request', $request->all());

        // ✅ Validate only existing columns
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'gender'     => 'required|string',
            'date_of_birth' => 'required|date',

            'personal_email' => 'nullable|email',
            'phone_number'   => 'nullable|string|max:20',

            'department_id' => 'required|exists:departments,id',
            'major_id'      => 'required|exists:majors,id',

            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // ✅ Prevent duplicate registration
        $exists = DB::table('registrations')
            ->where('personal_email', $request->personal_email)
            ->orWhere('phone_number', $request->phone_number)
            ->first();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Registration already exists'
            ], 409);
        }

        // ✅ Upload profile picture
        $profilePicturePath = null;
        if ($request->hasFile('profile_picture')) {
            $profilePicturePath = $request->file('profile_picture')
                ->store('profiles', 'public');
        }

        // ✅ Generate student password
        $plainPassword = 'novatech' . now()->format('Ymd');

        // ✅ Insert registration
        $data = [
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'full_name_kh' => $request->full_name_kh,
            'full_name_en' => $request->full_name_en,

            'gender' => $request->gender,
            'date_of_birth' => $request->date_of_birth,

            'address' => $request->address,
            'current_address' => $request->current_address,

            'phone_number' => $request->phone_number,
            'personal_email' => $request->personal_email,

            'high_school_name' => $request->high_school_name,
            'graduation_year' => $request->graduation_year,
            'grade12_result' => $request->grade12_result,

            'department_id' => $request->department_id,
            'major_id' => $request->major_id,
            'faculty' => $request->faculty,
            'shift' => $request->shift,
            'batch' => $request->batch,
            'academic_year' => $request->academic_year,

            // ✅ Father
            'father_name' => $request->father_name,
            'fathers_date_of_birth' => $request->fathers_date_of_birth,
            'fathers_nationality' => $request->fathers_nationality,
            'fathers_job' => $request->fathers_job,
            'fathers_phone_number' => $request->fathers_phone_number,

            // ✅ Mother
            'mother_name' => $request->mother_name,
            'mother_date_of_birth' => $request->mother_date_of_birth,
            'mother_nationality' => $request->mother_nationality,
            'mothers_job' => $request->mothers_job,
            'mother_phone_number' => $request->mother_phone_number,

            // ✅ Guardian (FIXED)
            'guardian_name' => $request->guardian_name,
            'guardian_phone_number' => $request->guardian_phone_number,

            // ✅ Emergency (FIXED)
            'emergency_contact_name' => $request->emergency_contact_name,
            'emergency_contact_phone_number' => $request->emergency_contact_phone_number,

            'profile_picture_path' => $profilePicturePath,

            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('registrations')->insert($data);

        // ✅ Create student user account
        $user = \App\Models\User::create([
            'name' => $request->first_name . ' ' . $request->last_name,
            'email' => $request->personal_email,
            'password' => Hash::make($plainPassword),
            'role' => 'student',
            'profile_picture_path' => $profilePicturePath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'student_account' => [
                'email' => $user->email,
                'password' => $plainPassword,
                'role' => 'student',
            ]
        ], 201);
    }
}
