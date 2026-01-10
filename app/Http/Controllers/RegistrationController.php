<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Models\Student;
use App\Models\User;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Registration request', $request->all());

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'full_name_kh' => 'nullable|string|max:255',
            'full_name_en' => 'nullable|string|max:255',
            'gender' => 'required|string',
            'date_of_birth' => 'required|date',
            'personal_email' => 'nullable|email',
            'phone_number' => 'nullable|string|max:20',
            'department_id' => 'required|exists:departments,id',
            'major_id' => 'required|exists:majors,id',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:10240',
        ]);

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

        DB::beginTransaction();

        try {
            $profilePicturePath = null;
            if ($request->hasFile('profile_picture')) {
                $profilePicturePath = $request->file('profile_picture')->store('profiles', 'public');
            }

            $plainPassword = 'novatech' . now()->format('Ymd');
            $fullNameEn = $request->full_name_en ?? ($request->first_name . ' ' . $request->last_name);
            $fullNameKh = $request->full_name_kh ?? ($request->first_name . ' ' . $request->last_name);

            // ✅ ALL COLUMNS FROM IMAGE
            $registrationData = [
                // Personal Info
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'full_name_kh' => $fullNameKh,
                'full_name_en' => $fullNameEn,
                'gender' => $request->gender,
                'date_of_birth' => $request->date_of_birth,
                'address' => $request->address,
                'current_address' => $request->current_address,
                'phone_number' => $request->phone_number,
                'personal_email' => $request->personal_email,
                
                // Education Info
                'high_school_name' => $request->high_school_name,
                'graduation_year' => $request->graduation_year,
                'grade12_result' => $request->grade12_result,
                
                // Department & Study Info
                'department_id' => $request->department_id,
                'major_id' => $request->major_id,
                'faculty' => $request->faculty,
                'shift' => $request->shift,
                'batch' => $request->batch,
                'academic_year' => $request->academic_year,
                
                // Profile Picture
                'profile_picture_path' => $profilePicturePath,
                
                // Father Info
                'father_name' => $request->father_name,
                'fathers_date_of_birth' => $request->fathers_date_of_birth,
                'fathers_nationality' => $request->fathers_nationality,
                'fathers_job' => $request->fathers_job,
                'fathers_phone_number' => $request->fathers_phone_number,
                
                // Mother Info
                'mother_name' => $request->mother_name,
                'mother_date_of_birth' => $request->mother_date_of_birth,
                'mother_nationality' => $request->mother_nationality,
                'mothers_job' => $request->mothers_job,
                'mother_phone_number' => $request->mother_phone_number,
                
                // Guardian Info
                'guardian_name' => $request->guardian_name,
                'guardian_phone_number' => $request->guardian_phone_number,
                
                // Emergency Contact
                'emergency_contact_name' => $request->emergency_contact_name,
                'emergency_contact_phone_number' => $request->emergency_contact_phone_number,
                
                // Timestamps
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $registrationData = array_filter($registrationData, function($value) {
                return $value !== null;
            });

            $registrationId = DB::table('registrations')->insertGetId($registrationData);

            $user = User::create([
                'name' => $fullNameEn,
                'email' => $request->personal_email,
                'password' => Hash::make($plainPassword),
                'role' => 'student',
                'profile_picture_path' => $profilePicturePath,
            ]);

            $student = Student::create([
                'registration_id' => $registrationId,
                'user_id' => $user->id,
                'department_id' => $request->department_id,
                'full_name_kh' => $fullNameKh,
                'full_name_en' => $fullNameEn,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'nationality' => $request->fathers_nationality ?? 'Cambodian',
                'phone_number' => $request->phone_number,
                'address' => $request->address,
                'generation' => $request->batch ?? $request->academic_year,
                'parent_name' => $request->father_name ?? $request->mother_name,
                'parent_phone' => $request->fathers_phone_number ?? $request->mother_phone_number,
                'profile_picture_path' => $profilePicturePath,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'registration_id' => $registrationId,
                    'student_id' => $student->id,
                    'student_code' => $student->student_code,
                    'user_id' => $user->id,
                    'profile_picture_path' => $profilePicturePath,
                ],
                'student_account' => [
                    'email' => $user->email,
                    'password' => $plainPassword,
                    'role' => 'student',
                    'student_code' => $student->student_code,
                    'profile_picture_path' => $profilePicturePath,
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            if ($profilePicturePath) {
                $filePath = storage_path('app/public/' . $profilePicturePath);
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
            }

            Log::error('Registration error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        $registrations = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->leftJoin('students', 'registrations.id', '=', 'students.registration_id')
            ->select('registrations.*', 'departments.name as department_name', 'majors.major_name', 'students.student_code', 'students.id as student_id')
            ->orderBy('registrations.created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $registrations]);
    }

    public function show($id)
    {
        $registration = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->leftJoin('students', 'registrations.id', '=', 'students.registration_id')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->where('registrations.id', $id)
            ->select('registrations.*', 'departments.name as department_name', 'majors.major_name', 'students.student_code', 'students.id as student_id', 'users.email as student_email')
            ->first();

        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $registration]);
    }

    public function update(Request $request, $id)
    {
        $registration = DB::table('registrations')->where('id', $id)->first();

        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'full_name_kh' => 'nullable|string|max:255',
            'full_name_en' => 'nullable|string|max:255',
            'gender' => 'sometimes|required|string',
            'date_of_birth' => 'sometimes|required|date',
            'personal_email' => 'nullable|email',
            'phone_number' => 'nullable|string|max:20',
            'department_id' => 'sometimes|required|exists:departments,id',
            'major_id' => 'sometimes|required|exists:majors,id',
            'profile_picture' => 'nullable|image|mimes:jpg,jpeg,png|max:10240',
        ]);

        DB::beginTransaction();

        try {
            $newProfilePicturePath = null;

            if ($request->hasFile('profile_picture')) {
                if ($registration->profile_picture_path) {
                    $oldPath = storage_path('app/public/' . $registration->profile_picture_path);
                    if (File::exists($oldPath)) {
                        File::delete($oldPath);
                    }
                }

                $newProfilePicturePath = $request->file('profile_picture')->store('profiles', 'public');
                $validated['profile_picture_path'] = $newProfilePicturePath;
            }

            if (isset($validated['first_name']) || isset($validated['last_name'])) {
                $firstName = $validated['first_name'] ?? $registration->first_name;
                $lastName = $validated['last_name'] ?? $registration->last_name;
                
                if (!isset($validated['full_name_en'])) {
                    $validated['full_name_en'] = $firstName . ' ' . $lastName;
                }
            }

            $validated['updated_at'] = now();
            $validated = array_filter($validated, function($value) {
                return $value !== null;
            });

            DB::table('registrations')->where('id', $id)->update($validated);

            $student = Student::where('registration_id', $id)->first();
            if ($student) {
                $studentUpdate = [];
                
                if (isset($validated['full_name_kh'])) $studentUpdate['full_name_kh'] = $validated['full_name_kh'];
                if (isset($validated['full_name_en'])) $studentUpdate['full_name_en'] = $validated['full_name_en'];
                if (isset($validated['date_of_birth'])) $studentUpdate['date_of_birth'] = $validated['date_of_birth'];
                if (isset($validated['gender'])) $studentUpdate['gender'] = $validated['gender'];
                if (isset($validated['department_id'])) $studentUpdate['department_id'] = $validated['department_id'];
                if ($newProfilePicturePath) $studentUpdate['profile_picture_path'] = $newProfilePicturePath;

                if (!empty($studentUpdate)) {
                    $student->update($studentUpdate);
                }

                if ($newProfilePicturePath && $student->user_id) {
                    User::where('id', $student->user_id)->update(['profile_picture_path' => $newProfilePicturePath]);
                }
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Registration updated successfully', 'profile_picture_path' => $newProfilePicturePath]);

        } catch (\Exception $e) {
            DB::rollBack();

            if ($newProfilePicturePath) {
                $filePath = storage_path('app/public/' . $newProfilePicturePath);
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
            }

            return response()->json(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $registration = DB::table('registrations')->where('id', $id)->first();

        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        DB::beginTransaction();

        try {
            $student = Student::where('registration_id', $id)->first();
            if ($student) {
                if ($student->user_id) {
                    User::where('id', $student->user_id)->delete();
                }
                $student->delete();
            }

            if ($registration->profile_picture_path) {
                $filePath = storage_path('app/public/' . $registration->profile_picture_path);
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
            }

            DB::table('registrations')->where('id', $id)->delete();

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Registration deleted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()], 500);
        }
    }
}