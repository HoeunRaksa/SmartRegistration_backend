<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use App\Models\Student;
use App\Models\User;
use App\Models\Major;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Registration request received');

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
            'profile_picture' => 'nullable|file|mimes:jpeg,jpg,png|max:5120',
        ]);

        // Check if already registered
    $exists = DB::table('registrations')
    ->where(function ($q) use ($validated) {
        if (!empty($validated['personal_email'])) {
            $q->where('personal_email', $validated['personal_email']);
        }
        if (!empty($validated['phone_number'])) {
            $q->orWhere('phone_number', $validated['phone_number']);
        }
    })
    ->orderByDesc('id')
    ->first();


        if ($exists) {

            // ✅ If already PAID -> block new registration
            if (($exists->payment_status ?? null) === 'PAID') {
                return response()->json([
                    'success' => false,
                    'message' => 'Registration already paid. Cannot register again.'
                ], 409);
            }

            // ✅ If NOT PAID -> allow continue payment (return existing data, not create again)
            return response()->json([
                'success' => true,
                'message' => 'Registration already exists but not paid yet. Continue payment.',
                'data' => [
                    'registration_id' => $exists->id,
                    'payment_amount'  => $exists->payment_amount,
                    'payment_status'  => $exists->payment_status,
                    'payment_tran_id' => $exists->payment_tran_id,
                ],
            ], 200);
        }


        DB::beginTransaction();

        try {
            // Get major to get registration fee
            $major = Major::findOrFail($request->major_id);

            $profilePicturePath = null;
            if ($request->hasFile('profile_picture') && $request->file('profile_picture')->isValid()) {
                $uploadPath = public_path('uploads/profiles');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                $image = $request->file('profile_picture');
                $extension = $image->getClientOriginalExtension();
                $filename = time() . '_' . uniqid() . '.' . $extension;

                $image->move($uploadPath, $filename);
                $profilePicturePath = 'uploads/profiles/' . $filename;

                Log::info('Profile picture uploaded successfully: ' . $profilePicturePath);
            }

            $plainPassword = 'novatech' . now()->format('Ymd');
            $fullNameEn = $request->full_name_en ?? ($request->first_name . ' ' . $request->last_name);
            $fullNameKh = $request->full_name_kh ?? ($request->first_name . ' ' . $request->last_name);

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
                'profile_picture_path' => $profilePicturePath,

                // Parent Info
                'father_name' => $request->father_name,
                'fathers_date_of_birth' => $request->fathers_date_of_birth,
                'fathers_nationality' => $request->fathers_nationality,
                'fathers_job' => $request->fathers_job,
                'fathers_phone_number' => $request->fathers_phone_number,

                'mother_name' => $request->mother_name,
                'mother_date_of_birth' => $request->mother_date_of_birth,
                'mother_nationality' => $request->mother_nationality,
                'mothers_job' => $request->mothers_job,
                'mother_phone_number' => $request->mother_phone_number,

                // Guardian Info
                'guardian_name' => $request->guardian_name,
                'guardian_phone_number' => $request->guardian_phone_number,
                'emergency_contact_name' => $request->emergency_contact_name,
                'emergency_contact_phone_number' => $request->emergency_contact_phone_number,

                // Payment Info (from major)
                'payment_amount' => $major->registration_fee,
                'payment_status' => 'PENDING',

                // Timestamps
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $registrationData = array_filter($registrationData, function ($value) {
                return $value !== null;
            });

            $registrationId = DB::table('registrations')->insertGetId($registrationData);

            $user = User::create([
                'name' => $fullNameEn,
                'email' => $request->personal_email,
                'password' => Hash::make($plainPassword),
                'role' => 'register',
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
                    'payment_amount' => $major->registration_fee,
                    'payment_status' => 'PENDING',
                    'profile_picture_path' => $profilePicturePath,
                ],
                'student_account' => [
                    'email' => $user->email,
                    'password' => $plainPassword,
                    'role' => 'student',
                    'student_code' => $student->student_code,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($profilePicturePath && File::exists(public_path($profilePicturePath))) {
                File::delete(public_path($profilePicturePath));
            }

            Log::error('Registration error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

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
            ->leftJoin('payment_transactions', 'registrations.payment_tran_id', '=', 'payment_transactions.tran_id')
            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name',
                'majors.registration_fee',
                'students.student_code',
                'students.id as student_id',
                'payment_transactions.status as transaction_status'
            )
            ->orderBy('registrations.created_at', 'desc')
            ->get();

        // Add profile picture URL
        $registrations = $registrations->map(function ($reg) {
            if ($reg->profile_picture_path) {
                $reg->profile_picture_url = url($reg->profile_picture_path);
            }
            return $reg;
        });

        return response()->json(['success' => true, 'data' => $registrations]);
    }

    public function show($id)
    {
        $registration = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->leftJoin('students', 'registrations.id', '=', 'students.registration_id')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('payment_transactions', 'registrations.payment_tran_id', '=', 'payment_transactions.tran_id')
            ->where('registrations.id', $id)
            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name',
                'majors.registration_fee',
                'students.student_code',
                'students.id as student_id',
                'users.email as student_email',
                'payment_transactions.status as transaction_status'
            )
            ->first();

        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        if ($registration->profile_picture_path) {
            $registration->profile_picture_url = url($registration->profile_picture_path);
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
            'profile_picture' => 'nullable|file|mimes:jpeg,jpg,png|max:5120',
        ]);

        DB::beginTransaction();

        try {
            $newProfilePicturePath = null;

            if ($request->hasFile('profile_picture') && $request->file('profile_picture')->isValid()) {
                if ($registration->profile_picture_path) {
                    $oldPath = public_path($registration->profile_picture_path);
                    if (File::exists($oldPath)) {
                        File::delete($oldPath);
                    }
                }

                $uploadPath = public_path('uploads/profiles');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                $image = $request->file('profile_picture');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();

                $image->move($uploadPath, $filename);
                $newProfilePicturePath = 'uploads/profiles/' . $filename;
                $validated['profile_picture_path'] = $newProfilePicturePath;
            }

            // Update payment amount if major changed
            if (isset($validated['major_id'])) {
                $major = Major::findOrFail($validated['major_id']);
                $validated['payment_amount'] = $major->registration_fee;
            }

            if (isset($validated['first_name']) || isset($validated['last_name'])) {
                $firstName = $validated['first_name'] ?? $registration->first_name;
                $lastName = $validated['last_name'] ?? $registration->last_name;

                if (!isset($validated['full_name_en'])) {
                    $validated['full_name_en'] = $firstName . ' ' . $lastName;
                }
            }

            $validated['updated_at'] = now();
            $validated = array_filter($validated, function ($value) {
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

            return response()->json([
                'success' => true,
                'message' => 'Registration updated successfully',
                'profile_picture_path' => $newProfilePicturePath
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($newProfilePicturePath) && $newProfilePicturePath && File::exists(public_path($newProfilePicturePath))) {
                File::delete(public_path($newProfilePicturePath));
            }

            Log::error('Update error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage()
            ], 500);
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
                $filePath = public_path($registration->profile_picture_path);
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
            }

            DB::table('registrations')->where('id', $id)->delete();

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Registration deleted successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Delete failed: ' . $e->getMessage()
            ], 500);
        }
    }
    public function payLater($id)
    {
        $reg = DB::table('registrations')->where('id', $id)->first();
        if (!$reg) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        DB::table('registrations')->where('id', $id)->update([
            'payment_status' => 'PENDING',
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment deferred. You can pay later at admin office.',
        ]);
    }
    public function markPaidCash($id, Request $request)
    {
        $registration = DB::table('registrations')->where('id', $id)->first();
        if (!$registration) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        DB::beginTransaction();
        try {
            $tranId = $registration->payment_tran_id ?? ('CASH-' . $id . '-' . time());
            $amount = $registration->payment_amount ?? 0;

            DB::table('payment_transactions')->updateOrInsert(
                ['tran_id' => $tranId],
                [
                    'amount' => $amount,
                    'status' => 'PAID',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::table('registrations')->where('id', $id)->update([
                'payment_tran_id' => $tranId,
                'payment_status' => 'PAID',
                'payment_date' => now(),
                'updated_at' => now(),
            ]);

            // upgrade user role (same logic as callback)
            if ($registration->personal_email) {
                User::where('email', $registration->personal_email)
                    ->where('role', 'register')
                    ->update(['role' => 'student']);
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Marked as PAID (Cash).']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }
}
