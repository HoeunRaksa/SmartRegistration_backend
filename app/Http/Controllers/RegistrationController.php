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
    /**
     * ✅ Helper: normalize semester (1|2)
     */
    private function normalizeSemester($semester): int
    {
        $s = (int) ($semester ?? 1);
        return in_array($s, [1, 2], true) ? $s : 1;
    }

    /**
     * ✅ Helper: find student by registration OR by email/phone (works for old+new flow)
     * This prevents breaking existing data when students.registration_id is still the first registration.
     */
    private function findStudentByRegistrationOrContact(int $registrationId, ?string $email, ?string $phone)
    {
        // 1) Direct link (old flow)
        $student = DB::table('students')
            ->where('registration_id', $registrationId)
            ->select('id', 'student_code', 'user_id', 'registration_id')
            ->first();

        if ($student) {
            return $student;
        }

        // 2) Match by email/phone (new flow safe)
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
            ->select(
                'students.id',
                'students.student_code',
                'students.user_id',
                'students.registration_id'
            )
            ->orderByDesc('students.id')
            ->first();
    }

    /**
     * ✅ Helper: upsert academic period WITHOUT overwriting created_at.
     */
    private function upsertAcademicPeriodNoCreatedAtOverwrite(int $studentId, string $academicYear, int $semester, array $data): void
    {
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
     * ✅ Helper: ensure academic period exists (idempotent)
     */
    private function ensureAcademicPeriod(
        int $studentId,
        string $academicYear,
        int $semester,
        float $tuitionAmount
    ): void {
        $this->upsertAcademicPeriodNoCreatedAtOverwrite(
            $studentId,
            $academicYear,
            $semester,
            [
                'status' => 'ACTIVE',
                'tuition_amount' => $tuitionAmount,
                'payment_status' => 'PENDING',
                'paid_at' => null,
            ]
        );
    }

    /**
     * ✅ Helper: set column if exists (prevents crash if DB column missing)
     */
    private function putIfColumnExists(array &$data, string $table, string $column, $value): void
    {
        if (Schema::hasColumn($table, $column)) {
            $data[$column] = $value;
        }
    }

    public function store(Request $request)
    {
        Log::info('Registration request received');

        /**
         * ✅ VALIDATE ALL FIELDS you might insert (FULL)
         * - If any of these columns are NOT NULL in DB, they must be provided or nullable in DB.
         */
        $validated = $request->validate([
            // required
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'gender' => 'required|string|max:50',
            'date_of_birth' => 'required|date',
            'personal_email' => 'required|email|max:255',
            'department_id' => 'required|exists:departments,id',
            'major_id' => 'required|exists:majors,id',
            'academic_year' => 'required|string|max:20',

            // important required for your DB error
            'high_school_name' => 'required|string|max:255',

            // optional personal
            'full_name_kh' => 'nullable|string|max:255',
            'full_name_en' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'shift' => 'nullable|string|max:50',
            'batch' => 'nullable|string|max:50',
            'faculty' => 'nullable|string|max:255',
            'semester' => 'nullable|integer|in:1,2',

            // optional school
            'graduation_year' => 'nullable|string|max:10',
            'grade12_result' => 'nullable|string|max:50',

            // optional address
            'address' => 'nullable|string|max:255',
            'current_address' => 'nullable|string|max:255',

            // optional father
            'father_name' => 'nullable|string|max:255',
            'fathers_date_of_birth' => 'nullable|date',
            'fathers_nationality' => 'nullable|string|max:100',
            'fathers_job' => 'nullable|string|max:255',
            'fathers_phone_number' => 'nullable|string|max:20',

            // optional mother
            'mother_name' => 'nullable|string|max:255',
            'mother_date_of_birth' => 'nullable|date',
            'mother_nationality' => 'nullable|string|max:100',
            'mothers_job' => 'nullable|string|max:255',
            'mother_phone_number' => 'nullable|string|max:20',

            // optional guardian/emergency
            'guardian_name' => 'nullable|string|max:255',
            'guardian_phone_number' => 'nullable|string|max:20',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone_number' => 'nullable|string|max:20',

            // optional file
            'profile_picture' => 'nullable|file|mimes:jpeg,jpg,png|max:5120',
        ]);

        $semester = $this->normalizeSemester($request->input('semester', 1));

        // For building names
        $fullNameEn = $request->full_name_en ?? ($request->first_name . ' ' . $request->last_name);
        $fullNameKh = $request->full_name_kh ?? $fullNameEn;

        /**
         * ✅ Load major fee (source of truth)
         */
        $major = Major::findOrFail($request->major_id);
        $tuition = (float) ($major->registration_fee ?? 0);

        /**
         * ✅ If student already exists (ANY year), do NOT create a new user/student again.
         */
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

        /**
         * ✅ NEW FLOW CHECK:
         * If student exists AND already PAID for this academic_year+semester => block.
         */
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
            /**
             * ✅ Upload profile picture (optional)
             */
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

            /**
             * ✅ FULL registration row (put everything)
             * We also guard with Schema::hasColumn so you can evolve DB safely.
             */
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

            // REQUIRED per your DB
            $this->putIfColumnExists($regData, 'registrations', 'high_school_name', $validated['high_school_name']);

            // optional columns (safe)
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

            // semester column optional
            $this->putIfColumnExists($regData, 'registrations', 'semester', $semester);

            $registrationId = DB::table('registrations')->insertGetId($regData);

            /**
             * ✅ Returning student path
             */
            if ($existingStudent) {
                if ($profilePicturePath && !empty($existingStudent->user_id)) {
                    User::where('id', $existingStudent->user_id)->update([
                        'profile_picture_path' => $profilePicturePath
                    ]);
                }

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

            /**
             * ✅ First-time student path
             */
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

    public function index(Request $request)
    {
        $semester = $this->normalizeSemester($request->input('semester', 1));

        $registrations = DB::table('registrations')
            ->join('departments', 'registrations.department_id', '=', 'departments.id')
            ->join('majors', 'registrations.major_id', '=', 'majors.id')

            ->leftJoin('users', 'users.email', '=', 'registrations.personal_email')

            ->leftJoin('students', function ($join) {
                $join->on('students.registration_id', '=', 'registrations.id')
                    ->orOn('students.user_id', '=', 'users.id');
            })

            ->leftJoin('student_academic_periods as sap', function ($join) use ($semester) {
                $join->on('sap.student_id', '=', 'students.id')
                    ->on('sap.academic_year', '=', 'registrations.academic_year')
                    ->where('sap.semester', '=', $semester);
            })

            ->select(
                'registrations.*',
                'departments.name as department_name',
                'majors.major_name',
                'majors.registration_fee',
                'students.student_code',
                'students.id as student_id',
                DB::raw('COALESCE(sap.payment_status, "PENDING") as period_payment_status'),
                'sap.paid_at as period_paid_at',
                'sap.tuition_amount as period_tuition_amount',
                DB::raw($semester . ' as period_semester')
            )
            ->orderBy('registrations.created_at', 'desc')
            ->get();

        $registrations = $registrations->map(function ($reg) {
            if (!empty($reg->profile_picture_path)) {
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

        if (!empty($registration->profile_picture_path)) {
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
            'gender' => 'sometimes|required|string|max:50',
            'date_of_birth' => 'sometimes|required|date',
            'personal_email' => 'sometimes|required|email|max:255',
            'phone_number' => 'nullable|string|max:20',
            'department_id' => 'sometimes|required|exists:departments,id',
            'major_id' => 'sometimes|required|exists:majors,id',
            'academic_year' => 'sometimes|required|string|max:20',

            // ✅ allow updating these too
            'high_school_name' => 'sometimes|required|string|max:255',
            'graduation_year' => 'nullable|string|max:10',
            'grade12_result' => 'nullable|string|max:50',
            'faculty' => 'nullable|string|max:255',
            'shift' => 'nullable|string|max:50',
            'batch' => 'nullable|string|max:50',
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

        DB::beginTransaction();

        try {
            $newProfilePicturePath = null;

            if ($request->hasFile('profile_picture') && $request->file('profile_picture')->isValid()) {
                if (!empty($registration->profile_picture_path)) {
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
            $validated = array_filter($validated, fn ($v) => $v !== null);

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
        } catch (\Throwable $e) {
            DB::rollBack();

            if (!empty($newProfilePicturePath) && File::exists(public_path($newProfilePicturePath))) {
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

            if (!empty($registration->profile_picture_path)) {
                $filePath = public_path($registration->profile_picture_path);
                if (File::exists($filePath)) {
                    File::delete($filePath);
                }
            }

            DB::table('registrations')->where('id', $id)->delete();

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Registration deleted successfully']);
        } catch (\Throwable $e) {
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

        $this->ensureAcademicPeriod(
            (int) $student->id,
            (string) $reg->academic_year,
            (int) $semester,
            (float) $majorFee
        );

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

        $semester = $this->normalizeSemester($request->input('semester', 1));

        DB::beginTransaction();
        try {
            $student = $this->findStudentByRegistrationOrContact(
                (int) $id,
                $registration->personal_email ?? null,
                $registration->phone_number ?? null
            );

            if (!$student) {
                return response()->json(['success' => false, 'message' => 'Student not found for this registration'], 404);
            }

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
