<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Registration request', $request->all());

        // ✅ Validate only existing columns
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'full_name_kh' => 'nullable|string|max:255',
            'full_name_en' => 'nullable|string|max:255',
            
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
            ->when($validated['personal_email'] ?? null, function ($q) use ($validated) {
                $q->where('personal_email', $validated['personal_email']);
            })
            ->when($validated['phone_number'] ?? null, function ($q) use ($validated) {
                $q->orWhere('phone_number', $validated['phone_number']);
            })
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

        // ✅ Auto-generate full_name_en if not provided
        $fullNameEn = $request->full_name_en ?? ($request->first_name . ' ' . $request->last_name);
        $fullNameKh = $request->full_name_kh ?? ($request->first_name . ' ' . $request->last_name);

        // ✅ Insert registration
        $data = [
            'first_name' => $request->first_name,
            'last_name'  => $request->last_name,
            'full_name_kh' => $fullNameKh,
            'full_name_en' => $fullNameEn,

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

            // ✅ Guardian
            'guardian_name' => $request->guardian_name,
            'guardian_phone_number' => $request->guardian_phone_number,

            // ✅ Emergency
            'emergency_contact_name' => $request->emergency_contact_name,
            'emergency_contact_phone_number' => $request->emergency_contact_phone_number,

            'profile_picture_path' => $profilePicturePath,

            'created_at' => now(),
            'updated_at' => now(),
        ];

        // ✅ Remove null values to prevent database errors
        $data = array_filter($data, function($value) {
            return $value !== null;
        });

        DB::table('registrations')->insert($data);

        // ✅ Create student user account
        $user = \App\Models\User::create([
            'name' => $fullNameEn,
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

    // ✅ Get all registrations
    public function index()
    {
        $registrations = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name'
            )
            ->orderBy('registrations.created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $registrations
        ]);
    }

    // ✅ Get single registration
    public function show($id)
    {
        $registration = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->where('registrations.id', $id)
            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name'
            )
            ->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $registration
        ]);
    }

    // ✅ Update registration
    public function update(Request $request, $id)
    {
        $registration = DB::table('registrations')->where('id', $id)->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found'
            ], 404);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name'  => 'sometimes|required|string|max:255',
            'full_name_kh' => 'nullable|string|max:255',
            'full_name_en' => 'nullable|string|max:255',
            'gender'     => 'sometimes|required|string',
            'date_of_birth' => 'sometimes|required|date',
            'personal_email' => 'nullable|email',
            'phone_number'   => 'nullable|string|max:20',
            'department_id' => 'sometimes|required|exists:departments,id',
            'major_id'      => 'sometimes|required|exists:majors,id',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // Handle profile picture upload
        if ($request->hasFile('profile_picture')) {
            // Delete old picture if exists
            if ($registration->profile_picture_path) {
                $oldPath = storage_path('app/public/' . $registration->profile_picture_path);
                if (File::exists($oldPath)) {
                    File::delete($oldPath);
                }
            }

            $validated['profile_picture_path'] = $request->file('profile_picture')
                ->store('profiles', 'public');
        }

        // Auto-generate full names if not provided
        if (isset($validated['first_name']) || isset($validated['last_name'])) {
            $firstName = $validated['first_name'] ?? $registration->first_name;
            $lastName = $validated['last_name'] ?? $registration->last_name;
            
            if (!isset($validated['full_name_en'])) {
                $validated['full_name_en'] = $firstName . ' ' . $lastName;
            }
        }

        $validated['updated_at'] = now();

        // Remove null values
        $validated = array_filter($validated, function($value) {
            return $value !== null;
        });

        DB::table('registrations')
            ->where('id', $id)
            ->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Registration updated successfully'
        ]);
    }

    // ✅ Delete registration
    public function destroy($id)
    {
        $registration = DB::table('registrations')->where('id', $id)->first();

        if (!$registration) {
            return response()->json([
                'success' => false,
                'message' => 'Registration not found'
            ], 404);
        }

        // Delete profile picture if exists
        if ($registration->profile_picture_path) {
            $filePath = storage_path('app/public/' . $registration->profile_picture_path);
            if (File::exists($filePath)) {
                File::delete($filePath);
            }
        }

        DB::table('registrations')->where('id', $id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registration deleted successfully'
        ]);
    }
}