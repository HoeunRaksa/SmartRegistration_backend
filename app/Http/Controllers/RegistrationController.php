<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use App\Models\Student;
use App\Models\User;
use App\Models\Major;

class RegistrationController extends Controller
{
    /* ============================================================
     * ✅ FIX 1: NEVER overwrite PAID -> PENDING
     * ✅ FIX 2: INDEX join must not attach wrong student (no OR join)
     * ============================================================ */

    private function normalizeSemester($semester): int
    {
        $s = (int) ($semester ?? 1);
        return in_array($s, [1, 2], true) ? $s : 1;
    }

    private function putIfColumnExists(array &$data, string $table, string $column, $value): void
    {
        if (Schema::hasColumn($table, $column)) {
            $data[$column] = $value;
        }
    }

    /**
     * ✅ Find student by direct registration_id OR by contact (safe for old+new flow)
     */
    private function findStudentByRegistrationOrContact(int $registrationId, ?string $email, ?string $phone)
    {
        $student = DB::table('students')
            ->where('registration_id', $registrationId)
            ->select('id', 'student_code', 'user_id', 'registration_id', 'department_id')
            ->first();

        if ($student) return $student;

        return DB::table('students')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('registrations', 'students.registration_id', '=', 'registrations.id')
            ->where(function ($q) use ($email, $phone) {
                if (!empty($email)) {
                    $q->where('users.email', $email)
                      ->orWhere('registrations.personal_email', $email);
                }
                if (!empty($phone)) {
                    $q->orWhere('registrations.phone_number', $phone);
                }
            })
            ->select('students.id', 'students.student_code', 'students.user_id', 'students.registration_id', 'students.department_id')
            ->orderByDesc('students.id')
            ->first();
    }

    /**
     * ✅ UPSERT period but DO NOT touch payment_status/paid_at unless you explicitly want
     */
    private function upsertAcademicPeriodSafe(int $studentId, string $academicYear, int $semester, array $data): void
    {
        // update existing row
        $updated = DB::table('student_academic_periods')
            ->where('student_id', $studentId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->update(array_merge($data, ['updated_at' => now()]));

        if ($updated === 0) {
            DB::table('student_academic_periods')->insert(array_merge([
                'student_id' => $studentId,
                'academic_year' => $academicYear,
                'semester' => $semester,
                'created_at' => now(),
                'updated_at' => now(),
            ], $data));
        }
    }

    /**
     * ✅ Ensure period exists WITHOUT overwriting paid status
     * - Insert-only; if exists, we update only NON-payment fields
     */
    private function ensureAcademicPeriod(int $studentId, string $academicYear, int $semester, float $tuitionAmount): void
    {
        $existing = DB::table('student_academic_periods')
            ->where('student_id', $studentId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->first();

        if ($existing) {
            // ✅ do NOT change payment_status or paid_at
            DB::table('student_academic_periods')
                ->where('id', $existing->id)
                ->update([
                    'status' => 'ACTIVE',
                    'tuition_amount' => $tuitionAmount, // ok to refresh amount
                    'updated_at' => now(),
                ]);
            return;
        }

        DB::table('student_academic_periods')->insert([
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'status' => 'ACTIVE',
            'tuition_amount' => $tuitionAmount,
            'payment_status' => 'PENDING',
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function store(Request $request)
    {
        Log::info('Registration request received');

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'gender' => 'required|string|max:50',
            'date_of_birth' => 'required|date',
            'personal_email' => 'required|email|max:255',
            'department_id' => 'required|exists:departments,id',
            'major_id' => 'required|exists:majors,id',
            'academic_year' => 'required|string|max:20',

            'high_school_name' => 'required|string|max:255',

            'full_name_kh' => 'nullable|string|max:255',
            'full_name_en' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'shift' => 'nullable|string|max:50',
            'batch' => 'nullable|string|max:50',
            'faculty' => 'nullable|string|max:255',
            'semester' => 'nullable|integer|in:1,2',

            'graduation_year' => 'nullable|string|max:10',
            'grade12_result' => 'nullable|string|max:50',

            'address' => 'nullable|string|max:255',
            'current_address' => 'nullable|string|max:255',

            'father_name' => 'nullable|string|max:255',
            'fathers_date_of_birth' => 'nullable|date',
            'fathers_nationality' => 'nullable|string|max:100',
            'fathers_job' => 'nullable|string|max:255',
            'fathers_phone_number' => 'nullable|string|max:20',

            'mother_name' => 'nullable|string|max:255',
            'mother_date_of_birth' => 'nullable|date',
            'mother_nationality' => 'nullable|string|max:100',
            'mothers_job' => 'nullable|string|max:255',
            'mother_phone_number' => 'nullable|string|max:20',

            'guardian_name' => 'nullable|string|max:255',
            'guardian_phone_number' => 'nullable|string|max:20',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone_number' => 'nullable|string|max:20',

            'profile_picture' => 'nullable|file|mimes:jpeg,jpg,png|max:5120',
        ]);

        $semester = $this->normalizeSemester($request->input('semester', 1));

        $fullNameEn = $request->full_name_en ?? ($request->first_name . ' ' . $request->last_name);
        $fullNameKh = $request->full_name_kh ?? $fullNameEn;

        $major = Major::findOrFail($request->major_id);
        $tuition = (float) ($major->registration_fee ?? 0);

        // Find existing student by email/phone (safe)
        $existingStudent = DB::table('students')
            ->leftJoin('users', 'students.user_id', '=', 'users.id')
            ->leftJoin('registrations', 'students.registration_id', '=', 'registrations.id')
            ->where(function ($q) use ($validated) {
                $q->where('users.email', $validated['personal_email'])
                  ->orWhere('registrations.personal_email', $validated['personal_email']);

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

        // If already PAID for same year+semester => block
        if ($existingStudent) {
            $period = DB::table('student_academic_periods')
                ->where('student_id', $existingStudent->student_id)
                ->where('academic_year', $request->academic_year)
                ->where('semester', $semester)
                ->first();

            if ($period && strtoupper((string) $period->payment_status) === 'PAID') {
                return response()->json([
                    'success' => false,
                    'message' => 'Already paid for this academic year and semester.'
                ], 409);
            }
        }

        DB::beginTransaction();

        try {
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

            $regData = [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'full_name_kh' => $fullNameKh,
                'full_name_en' => $fullNameEn,
                'gender' => $validated['gender'],
                'date_of_birth' => $validated['date_of_birth'],
                'phone_number' => $validated['phone_number'] ?? null,
                'personal_email' => $validated['personal_email'],
                'department_id' => $validated['department_id'],
                'major_id' => $validated['major_id'],
                'academic_year' => $validated['academic_year'],
                'profile_picture_path' => $profilePicturePath,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $this->putIfColumnExists($regData, 'registrations', 'high_school_name', $validated['high_school_name']);
            $this->putIfColumnExists($regData, 'registrations', 'graduation_year', $validated['graduation_year'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'grade12_result', $validated['grade12_result'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'faculty', $validated['faculty'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'shift', $validated['shift'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'batch', $validated['batch'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'address', $validated['address'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'current_address', $validated['current_address'] ?? null);

            $this->putIfColumnExists($regData, 'registrations', 'father_name', $validated['father_name'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'fathers_date_of_birth', $validated['fathers_date_of_birth'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'fathers_nationality', $validated['fathers_nationality'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'fathers_job', $validated['fathers_job'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'fathers_phone_number', $validated['fathers_phone_number'] ?? null);

            $this->putIfColumnExists($regData, 'registrations', 'mother_name', $validated['mother_name'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'mother_date_of_birth', $validated['mother_date_of_birth'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'mother_nationality', $validated['mother_nationality'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'mothers_job', $validated['mothers_job'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'mother_phone_number', $validated['mother_phone_number'] ?? null);

            $this->putIfColumnExists($regData, 'registrations', 'guardian_name', $validated['guardian_name'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'guardian_phone_number', $validated['guardian_phone_number'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'emergency_contact_name', $validated['emergency_contact_name'] ?? null);
            $this->putIfColumnExists($regData, 'registrations', 'emergency_contact_phone_number', $validated['emergency_contact_phone_number'] ?? null);

            $this->putIfColumnExists($regData, 'registrations', 'semester', $semester);

            $registrationId = DB::table('registrations')->insertGetId($regData);

            // Existing student path
            if ($existingStudent) {
                if ($profilePicturePath && !empty($existingStudent->user_id)) {
                    User::where('id', $existingStudent->user_id)->update([
                        'profile_picture_path' => $profilePicturePath
                    ]);
                }

                // ✅ ensure period exists but won't reset paid
                $this->ensureAcademicPeriod(
                    (int) $existingStudent->student_id,
                    (string) $validated['academic_year'],
                    (int) $semester,
                    (float) $tuition
                );

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Registration created for new academic year. Continue payment.',
                    'data' => [
                        'registration_id' => $registrationId,
                        'student_id' => $existingStudent->student_id,
                        'student_code' => $existingStudent->student_code,
                        'academic_year' => $validated['academic_year'],
                        'semester' => $semester,
                        'payment_status' => 'PENDING',
                        'payment_amount' => $tuition,
                    ],
                    'student_account' => [
                        'email' => $existingStudent->user_email ?? $validated['personal_email'],
                        'password' => null,
                    ]
                ], 201);
            }

            // First time student path
            $plainPassword = 'novatech' . now()->format('Ymd');

            $user = User::create([
                'name' => $fullNameEn,
                'email' => $validated['personal_email'],
                'password' => Hash::make($plainPassword),
                'role' => 'register',
                'profile_picture_path' => $profilePicturePath,
            ]);

            $student = Student::create([
                'registration_id' => $registrationId,
                'user_id' => $user->id,
                'department_id' => $validated['department_id'],
                'full_name_kh' => $fullNameKh,
                'full_name_en' => $fullNameEn,
                'date_of_birth' => $validated['date_of_birth'],
                'gender' => $validated['gender'],
                'phone_number' => $validated['phone_number'] ?? null,
                'profile_picture_path' => $profilePicturePath,
            ]);

            // ✅ ensure period exists but won't reset paid
            $this->ensureAcademicPeriod(
                (int) $student->id,
                (string) $validated['academic_year'],
                (int) $semester,
                (float) $tuition
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'registration_id' => $registrationId,
                    'student_id' => $student->id,
                    'student_code' => $student->student_code,
                    'academic_year' => $validated['academic_year'],
                    'semester' => $semester,
                    'payment_status' => 'PENDING',
                    'payment_amount' => $tuition,
                ],
                'student_account' => [
                    'email' => $user->email,
                    'password' => $plainPassword,
                ]
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            if (!empty($profilePicturePath) && File::exists(public_path($profilePicturePath))) {
                File::delete(public_path($profilePicturePath));
            }

            Log::error('Registration store error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ✅ FIXED INDEX:
     * - no OR join
     * - student_id resolved by COALESCE(s_by_reg, s_by_user)
     * - sap joins correctly => paid won't show pending
     */
public function index(Request $request)
{
    $semester = $this->normalizeSemester($request->input('semester', 1));

    $registrations = DB::table('registrations as r')
        ->join('departments as d', 'r.department_id', '=', 'd.id')
        ->join('majors as m', 'r.major_id', '=', 'm.id')

        // link user by email (ok)
        ->leftJoin('users as u', 'u.email', '=', 'r.personal_email')

        // ✅ NEW: find student primarily by user_id (stable across years)
        ->leftJoin('students as s', function ($join) {
            $join->on('s.user_id', '=', 'u.id');
        })

        // ✅ join period using s.id (student_id)
        ->leftJoin('student_academic_periods as sap', function ($join) use ($semester) {
            $join->on('sap.student_id', '=', 's.id')
                ->on('sap.academic_year', '=', 'r.academic_year')
                ->where('sap.semester', '=', $semester);
        })

        ->select(
            'r.*',
            'd.name as department_name',
            'm.major_name',
            'm.registration_fee',
            's.student_code',
            's.id as student_id',
            DB::raw('COALESCE(sap.payment_status, "PENDING") as period_payment_status'),
            'sap.paid_at as period_paid_at',
            'sap.tuition_amount as period_tuition_amount',
            DB::raw($semester . ' as period_semester')
        )
        ->orderBy('r.created_at', 'desc')
        ->get();

    $registrations = $registrations->map(function ($reg) {
        if (!empty($reg->profile_picture_path)) {
            $reg->profile_picture_url = url($reg->profile_picture_path);
        }
        return $reg;
    });

    return response()->json(['success' => true, 'data' => $registrations]);
}



    public function payLater($id)
    {
        $reg = DB::table('registrations')->where('id', $id)->first();
        if (!$reg) {
            return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
        }

        $semester = $this->normalizeSemester(request()->input('semester', 1));

        $student = $this->findStudentByRegistrationOrContact(
            (int) $id,
            $reg->personal_email ?? null,
            $reg->phone_number ?? null
        );

        if (!$student) {
            return response()->json(['success' => false, 'message' => 'Student not found for this registration'], 404);
        }

        $majorFee = 0;
        if (!empty($reg->major_id)) {
            $m = Major::find($reg->major_id);
            $majorFee = (float) ($m->registration_fee ?? 0);
        }

        // ✅ ensure without overwriting PAID
        $this->ensureAcademicPeriod((int) $student->id, (string) $reg->academic_year, (int) $semester, (float) $majorFee);

        // ✅ do NOT force payment_status to pending if already paid
        $current = DB::table('student_academic_periods')
            ->where('student_id', $student->id)
            ->where('academic_year', $reg->academic_year)
            ->where('semester', $semester)
            ->first();

        if ($current && strtoupper((string)$current->payment_status) === 'PAID') {
            return response()->json([
                'success' => true,
                'message' => 'Already paid for this semester.',
            ]);
        }

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

private function upsertAcademicPeriodNoCreatedAtOverwrite(
    int $studentId,
    string $academicYear,
    int $semester,
    array $data
): void {
    $updated = DB::table('student_academic_periods')
        ->where('student_id', $studentId)
        ->where('academic_year', $academicYear)
        ->where('semester', $semester)
        ->update(array_merge($data, ['updated_at' => now()]));

    if ($updated === 0) {
        DB::table('student_academic_periods')->insert(array_merge([
            'student_id' => $studentId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'created_at' => now(),
            'updated_at' => now(),
        ], $data));
    }
}

public function markPaidCash($id, Request $request)
{
    $registration = DB::table('registrations')->where('id', $id)->first();
    if (!$registration) {
        return response()->json(['success' => false, 'message' => 'Registration not found'], 404);
    }

    $semester = $this->normalizeSemester($request->input('semester', 1));

    DB::beginTransaction();
    try {
        $student = $this->findStudentByRegistrationOrContact(
            (int) $id,
            $registration->personal_email ?? null,
            $registration->phone_number ?? null
        );

        if (!$student) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Student not found for this registration'
            ], 404);
        }

        if (empty($registration->academic_year)) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Registration academic_year is missing'
            ], 422);
        }

        // ✅ Upsert PAID into student_academic_periods (NEW SOURCE OF TRUTH)
        $this->upsertAcademicPeriodNoCreatedAtOverwrite(
            (int) $student->id,
            (string) $registration->academic_year,
            (int) $semester,
            [
                'status' => 'ACTIVE',
                'payment_status' => 'PAID',
                'paid_at' => now(),
            ]
        );

        // ✅ Upgrade user role
        if (!empty($registration->personal_email)) {
            User::where('email', $registration->personal_email)
                ->where('role', 'register')
                ->update(['role' => 'student']);
        }

        DB::commit();

        // ✅ Return what was saved so frontend can confirm immediately
        $period = DB::table('student_academic_periods')
            ->where('student_id', $student->id)
            ->where('academic_year', $registration->academic_year)
            ->where('semester', $semester)
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Marked as PAID (Cash).',
            'debug' => [
                'registration_id' => (int) $id,
                'student_id' => (int) $student->id,
                'academic_year' => (string) $registration->academic_year,
                'semester' => (int) $semester,
                'period' => $period,
            ]
        ]);
    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed: ' . $e->getMessage()
        ], 500);
    }
}

}
