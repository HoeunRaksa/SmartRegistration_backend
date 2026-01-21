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
            'academic_year' => 'required|string',
            'semester' => 'nullable|integer|in:1,2', // ✅ NEW (optional, default 1)
            'profile_picture' => 'nullable|file|mimes:jpeg,jpg,png|max:5120',
        ]);

        $semester = (int) ($request->semester ?? 1);

        /**
         * ✅ FIXED DUPLICATE CHECK
         * SAME person + SAME academic_year ONLY
         */
        $exists = DB::table('registrations')
            ->where(function ($q) use ($validated) {
                if (!empty($validated['personal_email'])) {
                    $q->where('personal_email', $validated['personal_email']);
                }
                if (!empty($validated['phone_number'])) {
                    $q->orWhere('phone_number', $validated['phone_number']);
                }
            })
            ->where('academic_year', $request->academic_year)
            ->orderByDesc('id')
            ->first();

        if ($exists) {

            /**
             * ✅ NEW FLOW CHECK:
             * If period already PAID for same academic_year + semester → block
             * If period exists but not PAID → continue payment
             */
            $studentLink = DB::table('students')
                ->where('registration_id', $exists->id)
                ->select('id as student_id', 'student_code')
                ->first();

            if ($studentLink) {
                $period = DB::table('student_academic_periods')
                    ->where('student_id', $studentLink->student_id)
                    ->where('academic_year', $request->academic_year)
                    ->where('semester', $semester)
                    ->first();

                if ($period) {
                    if (strtoupper((string) $period->payment_status) === 'PAID') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Already paid for this academic year and semester.'
                        ], 409);
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Academic period exists. Continue payment.',
                        'data' => [
                            'registration_id' => $exists->id,
                            'student_id' => $studentLink->student_id,
                            'student_code' => $studentLink->student_code,
                            'academic_year' => $period->academic_year,
                            'semester' => $period->semester,
                            'tuition_amount' => $period->tuition_amount,
                            'payment_status' => $period->payment_status,
                            'paid_at' => $period->paid_at,
                        ],
                    ], 200);
                }
            }

            // If registration exists but student_period not created yet → still allow create (safe)
        }

        /**
         * ✅ IMPORTANT REAL-WORLD FIX
         * If student already exists (old year), do NOT create new user/student again.
         * Just create a NEW registration row for the NEW academic_year.
         */
        $existingStudent = DB::table('students')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('registrations', 'students.registration_id', '=', 'registrations.id')
            ->where(function ($q) use ($validated) {
                if (!empty($validated['personal_email'])) {
                    $q->where('users.email', $validated['personal_email'])
                      ->orWhere('registrations.personal_email', $validated['personal_email']);
                }
                if (!empty($validated['phone_number'])) {
                    $q->orWhere('registrations.phone_number', $validated['phone_number']);
                }
            })
            ->select(
                'students.id as student_id',
                'students.student_code',
                'students.user_id',
                'users.email as user_email'
            )
            ->orderByDesc('students.id')
            ->first();

        DB::beginTransaction();

        try {
            $major = Major::findOrFail($request->major_id);

            $profilePicturePath = null;
            if ($request->hasFile('profile_picture') && $request->file('profile_picture')->isValid()) {
                $uploadPath = public_path('uploads/profiles');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                $filename = time() . '_' . uniqid() . '.' . $request->file('profile_picture')->getClientOriginalExtension();
                $request->file('profile_picture')->move($uploadPath, $filename);
                $profilePicturePath = 'uploads/profiles/' . $filename;
            }

            $plainPassword = 'novatech' . now()->format('Ymd');
            $fullNameEn = $request->full_name_en ?? ($request->first_name . ' ' . $request->last_name);
            $fullNameKh = $request->full_name_kh ?? $fullNameEn;

            /**
             * ✅ Existing student: create only registration row (new academic year)
             * and create/update student_academic_periods for payment tracking.
             */
            if ($existingStudent) {
                $registrationId = DB::table('registrations')->insertGetId([
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'full_name_kh' => $fullNameKh,
                    'full_name_en' => $fullNameEn,
                    'gender' => $request->gender,
                    'date_of_birth' => $request->date_of_birth,
                    'phone_number' => $request->phone_number,
                    'personal_email' => $request->personal_email,
                    'department_id' => $request->department_id,
                    'major_id' => $request->major_id,
                    'academic_year' => $request->academic_year,
                    'profile_picture_path' => $profilePicturePath,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Optional: update profile picture on existing user if uploaded
                if ($profilePicturePath && !empty($existingStudent->user_id)) {
                    User::where('id', $existingStudent->user_id)->update([
                        'profile_picture_path' => $profilePicturePath
                    ]);
                }

                // ✅ Create the academic period row (idempotent)
                DB::table('student_academic_periods')->updateOrInsert(
                    [
                        'student_id' => $existingStudent->student_id,
                        'academic_year' => $request->academic_year,
                        'semester' => $semester,
                    ],
                    [
                        'status' => 'ACTIVE',
                        'tuition_amount' => $major->registration_fee,
                        'payment_status' => 'PENDING',
                        'paid_at' => null,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Registration created for new academic year. Continue payment.',
                    'data' => [
                        'registration_id' => $registrationId,
                        'student_id' => $existingStudent->student_id,
                        'student_code' => $existingStudent->student_code,
                        'academic_year' => $request->academic_year,
                        'semester' => $semester,
                        'payment_status' => 'PENDING',
                    ],
                    'student_account' => [
                        'email' => $existingStudent->user_email ?? $request->personal_email,
                        'password' => null,
                    ]
                ], 201);
            }

            /**
             * ✅ No existing student: normal first-time registration
             */
            $registrationId = DB::table('registrations')->insertGetId([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'full_name_kh' => $fullNameKh,
                'full_name_en' => $fullNameEn,
                'gender' => $request->gender,
                'date_of_birth' => $request->date_of_birth,
                'phone_number' => $request->phone_number,
                'personal_email' => $request->personal_email,
                'department_id' => $request->department_id,
                'major_id' => $request->major_id,
                'academic_year' => $request->academic_year,
                'profile_picture_path' => $profilePicturePath,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

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
                'phone_number' => $request->phone_number,
                'profile_picture_path' => $profilePicturePath,
            ]);

            // ✅ Create the academic period row for first-time student
            DB::table('student_academic_periods')->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'academic_year' => $request->academic_year,
                    'semester' => $semester,
                ],
                [
                    'status' => 'ACTIVE',
                    'tuition_amount' => $major->registration_fee,
                    'payment_status' => 'PENDING',
                    'paid_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'registration_id' => $registrationId,
                    'student_id' => $student->id,
                    'student_code' => $student->student_code,
                    'academic_year' => $request->academic_year,
                    'semester' => $semester,
                    'payment_status' => 'PENDING',
                ],
                'student_account' => [
                    'email' => $user->email,
                    'password' => $plainPassword,
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($profilePicturePath && File::exists(public_path($profilePicturePath))) {
                File::delete(public_path($profilePicturePath));
            }

            Log::error($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Registration failed'
            ], 500);
        }
    }

    public function index()
    {
        $registrations = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')
            ->leftJoin('students', 'registrations.id', '=', 'students.registration_id')
            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name',
                'majors.registration_fee',
                'students.student_code',
                'students.id as student_id'
            )
            ->orderBy('registrations.created_at', 'desc')
            ->get();

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
            ->where('registrations.id', $id)
            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name',
                'majors.registration_fee',
                'students.student_code',
                'students.id as student_id',
                'users.email as student_email'
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
            'academic_year' => 'sometimes|required|string',
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

            if (isset($validated['major_id'])) {
                // major change no longer updates registration payment fields,
                // tuition is handled in student_academic_periods
                Major::findOrFail($validated['major_id']);
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

        // ✅ New flow: payLater should be applied on student_academic_periods, not registrations
        $student = DB::table('students')->where('registration_id', $id)->first();
        if (!$student) {
            return response()->json(['success' => false, 'message' => 'Student not found for this registration'], 404);
        }

        $semester = 1; // keep simple default; frontend can send later if needed

        DB::table('student_academic_periods')
            ->where('student_id', $student->id)
            ->where('academic_year', $reg->academic_year)
            ->where('semester', $semester)
            ->update([
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

        $semester = (int) ($request->semester ?? 1);
        if (!in_array($semester, [1, 2], true)) {
            return response()->json(['success' => false, 'message' => 'Invalid semester. Allowed: 1 or 2'], 422);
        }

        DB::beginTransaction();
        try {
            $student = DB::table('students')->where('registration_id', $id)->first();
            if (!$student) {
                return response()->json(['success' => false, 'message' => 'Student not found for this registration'], 404);
            }

            // ✅ Update academic period payment status
            DB::table('student_academic_periods')->updateOrInsert(
                [
                    'student_id' => $student->id,
                    'academic_year' => $registration->academic_year,
                    'semester' => $semester,
                ],
                [
                    'status' => 'ACTIVE',
                    'payment_status' => 'PAID',
                    'paid_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            // upgrade user role
            if (!empty($registration->personal_email)) {
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
