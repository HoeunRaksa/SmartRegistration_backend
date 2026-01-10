<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Registration request', $request->except(['password']));

        // ================= VALIDATION =================
        $validated = $request->validate([
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'gender'           => 'required|string',
            'date_of_birth'    => 'required|date',
            'personal_email'   => 'nullable|email',
            'phone_number'     => 'nullable|string|max:20',
            'department_id'    => 'required|exists:departments,id',
            'major_id'         => 'required|exists:majors,id',
            'profile_picture'  => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // ================= DUPLICATE CHECK =================
        $exists = DB::table('registrations')
            ->where('personal_email', $validated['personal_email'])
            ->orWhere('phone_number', $validated['phone_number'] ?? null)
            ->first();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Registration already exists for this email or phone number.',
            ], 409);
        }

        DB::beginTransaction();

        try {
            // ================= PROFILE IMAGE =================
            $profilePicturePath = null;
            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')
                    ->store('profiles', 'public');
            }

            // ================= INSERT REGISTRATION =================
            $registrationData = [
                'first_name'              => $validated['first_name'],
                'last_name'               => $validated['last_name'],
                'full_name_kh'            => $request->input('full_name_kh'),
                'full_name_en'            => $request->input(
                    'full_name_en',
                    $validated['first_name'] . ' ' . $validated['last_name']
                ),
                'gender'                  => $validated['gender'],
                'date_of_birth'           => $validated['date_of_birth'],
                'personal_email'          => $validated['personal_email'],
                'phone_number'            => $validated['phone_number'],
                'department_id'           => $validated['department_id'],
                'major_id'                => $validated['major_id'],
                'faculty'                 => $request->input('faculty'),
                'shift'                   => $request->input('shift', 'Morning'),
                'batch'                   => $request->input('batch'),
                'academic_year'           => $request->input('academic_year'),
                'high_school_name'        => $request->input('high_school_name'),
                'graduation_year'         => $request->input('graduation_year'),
                'grade12_result'          => $request->input('grade12_result'),
                'address'                 => $request->input('address'),
                'current_address'         => $request->input('current_address'),
                'father_name'             => $request->input('father_name'),
                'fathers_job'             => $request->input('fathers_job'),
                'mother_name'             => $request->input('mother_name'),
                'mothers_job'             => $request->input('mothers_job'),
                'guardian_name'           => $request->input('guardian_name'),
                'guardian_phone'          => $request->input('guardian_phone'),
                'emergency_contact_name'  => $request->input('emergency_contact_name'),
                'emergency_contact_phone' => $request->input('emergency_contact_phone'),
                'profile_picture_path'    => $profilePicturePath,
                'created_at'              => now(),
                'updated_at'              => now(),
            ];

            DB::table('registrations')->insert($registrationData);

            // ================= CREATE STUDENT ACCOUNT =================
            $studentAccount = null;
            $plainPassword = 'novatext' . now()->format('Ymd');

            if (!empty($validated['personal_email'])) {
                $studentAccount = User::create([
                    'name'                  => $validated['first_name'] . ' ' . $validated['last_name'],
                    'email'                 => $validated['personal_email'],
                    'password'              => Hash::make($plainPassword),
                    'role'                  => 'student',
                    'profile_picture_path'  => $profilePicturePath,
                ]);
            }

            DB::commit();

            // ================= RESPONSE =================
            return response()->json([
                'success' => true,
                'message' => 'Registration completed successfully',
                'student_account' => $studentAccount ? [
                    'email'    => $studentAccount->email,
                    'role'     => $studentAccount->role,
                    'password' => $plainPassword, // ⚠️ show once only
                ] : null,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Registration failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
