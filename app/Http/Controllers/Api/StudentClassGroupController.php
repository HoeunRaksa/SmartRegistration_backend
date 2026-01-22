<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Models\Student;
use App\Models\StudentClassGroup;
use App\Services\ClassGroupAllocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentClassGroupController extends Controller
{
    /**
     * GET /api/students/{studentId}/class-group?academic_year=2026-2027&semester=1
     * Show student class group for a period.
     */
    public function show($studentId, Request $request)
    {
        $academicYear = (string) $request->query('academic_year', '');
        $semester = (int) $request->query('semester', 1);
        $semester = in_array($semester, [1,2], true) ? $semester : 1;

        if ($academicYear === '') {
            return response()->json([
                'success' => false,
                'message' => 'academic_year is required (e.g. 2026-2027)'
            ], 422);
        }

        $student = Student::findOrFail($studentId);

        $row = StudentClassGroup::with(['classGroup'])
            ->where('student_id', $student->id)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $row,
        ]);
    }

    /**
     * POST /api/students/{studentId}/class-group/assign
     * Admin manually assign student to a specific class_group_id
     *
     * Body:
     * {
     *   "class_group_id": 10,
     *   "academic_year": "2026-2027",
     *   "semester": 1
     * }
     */
    public function assignManual($studentId, Request $request)
    {
        $validated = $request->validate([
            'class_group_id' => ['required', 'exists:class_groups,id'],
            'academic_year'  => ['required', 'string', 'max:20'],
            'semester'       => ['required', 'integer', 'in:1,2'],
        ]);

        $student = Student::findOrFail($studentId);
        $classGroup = ClassGroup::findOrFail((int)$validated['class_group_id']);

        // Safety: period must match group period
        if ((string)$classGroup->academic_year !== (string)$validated['academic_year']
            || (int)$classGroup->semester !== (int)$validated['semester']
        ) {
            return response()->json([
                'success' => false,
                'message' => 'class_group_id academic_year/semester does not match request period.',
                'debug' => [
                    'group_academic_year' => $classGroup->academic_year,
                    'group_semester' => (int)$classGroup->semester,
                ]
            ], 409);
        }

        DB::beginTransaction();
        try {
            // capacity check (lock to be safe)
            $groupLocked = ClassGroup::where('id', $classGroup->id)->lockForUpdate()->first();
            $capacity = (int) ($groupLocked->capacity ?? 40);
            if ($capacity <= 0) $capacity = 40;

            $used = (int) DB::table('student_class_groups')
                ->where('class_group_id', $groupLocked->id)
                ->where('academic_year', $validated['academic_year'])
                ->where('semester', (int)$validated['semester'])
                ->lockForUpdate()
                ->count();

            // If student already in same group, allow (idempotent)
            $existing = StudentClassGroup::where('student_id', $student->id)
                ->where('academic_year', $validated['academic_year'])
                ->where('semester', (int)$validated['semester'])
                ->first();

            $alreadySame = $existing && (int)$existing->class_group_id === (int)$groupLocked->id;

            if (!$alreadySame && $used >= $capacity) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Class group is full.',
                    'debug' => [
                        'capacity' => $capacity,
                        'used' => $used,
                    ]
                ], 409);
            }

            // upsert assignment
            $updated = DB::table('student_class_groups')
                ->where('student_id', $student->id)
                ->where('academic_year', $validated['academic_year'])
                ->where('semester', (int)$validated['semester'])
                ->update([
                    'class_group_id' => (int)$groupLocked->id,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                DB::table('student_class_groups')->insert([
                    'student_id' => $student->id,
                    'class_group_id' => (int)$groupLocked->id,
                    'academic_year' => $validated['academic_year'],
                    'semester' => (int)$validated['semester'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            $row = StudentClassGroup::with(['classGroup'])
                ->where('student_id', $student->id)
                ->where('academic_year', $validated['academic_year'])
                ->where('semester', (int)$validated['semester'])
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Student assigned to class group successfully.',
                'data' => $row,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Assign failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/students/{studentId}/class-group/auto
     * Auto pick group by major/year/semester/shift and assign (creates new group if full).
     *
     * Body:
     * {
     *   "major_id": 1,
     *   "academic_year": "2026-2027",
     *   "semester": 1,
     *   "shift": "Morning",
     *   "default_capacity": 40
     * }
     */
    public function assignAuto($studentId, Request $request, ClassGroupAllocator $allocator)
    {
        $validated = $request->validate([
            'major_id'          => ['required', 'exists:majors,id'],
            'academic_year'     => ['required', 'string', 'max:20'],
            'semester'          => ['required', 'integer', 'in:1,2'],
            'shift'             => ['nullable', 'string', 'max:50'],
            'default_capacity'  => ['nullable', 'integer', 'min:1', 'max:5000'],
        ]);

        $student = Student::findOrFail($studentId);

        DB::beginTransaction();
        try {
            $capacity = (int)($validated['default_capacity'] ?? 40);

            $group = $allocator->getOrCreateAvailableGroup(
                (int)$validated['major_id'],
                (string)$validated['academic_year'],
                (int)$validated['semester'],
                $validated['shift'] ?? null,
                $capacity
            );

            $allocator->assignStudentToGroup(
                (int)$student->id,
                (int)$group->id,
                (string)$validated['academic_year'],
                (int)$validated['semester']
            );

            DB::commit();

            $row = StudentClassGroup::with(['classGroup'])
                ->where('student_id', $student->id)
                ->where('academic_year', (string)$validated['academic_year'])
                ->where('semester', (int)$validated['semester'])
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'Student auto-assigned successfully.',
                'data' => $row,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Auto assign failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
