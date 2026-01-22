<?php

namespace App\Services;

use App\Models\ClassGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClassGroupAllocator
{
    /**
     * Pick an available class group for a major/year/semester/shift.
     * If all full => auto create next class group.
     *
     * IMPORTANT:
     * - If students table has class_group_id, we count from students.class_group_id.
     * - Else if pivot table student_class_groups exists, we count from that pivot.
     */
    public function getOrCreateAvailableGroup(
        int $majorId,
        string $academicYear,
        int $semester,
        ?string $shift = null,
        int $defaultCapacity = 1000
    ): ClassGroup {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

        // 1) Try find an existing group with free seats
        $q = ClassGroup::query()
            ->where('major_id', $majorId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester);

        if ($shift !== null && $shift !== '') {
            $q->where('shift', $shift);
        } else {
            // keep both null or empty shift consistent
            $q->where(function ($w) {
                $w->whereNull('shift')->orWhere('shift', '');
            });
        }

        $groups = $q->orderBy('id')->get();

        foreach ($groups as $g) {
            $capacity = (int) ($g->capacity ?? $defaultCapacity);
            if ($capacity <= 0) $capacity = $defaultCapacity;

            $used = $this->countStudentsInGroup($g->id, $academicYear, $semester);

            if ($used < $capacity) {
                return $g; // ✅ found free seat
            }
        }

        // 2) All full => create new class group automatically
        $nextName = $this->nextClassName($majorId, $academicYear, $semester, $shift);

        return ClassGroup::create([
            'class_name' => $nextName,
            'major_id' => $majorId,
            'academic_year' => $academicYear,
            'semester' => $semester,
            'shift' => $shift,
            'capacity' => $defaultCapacity,
        ]);
    }

    /**
     * Assign student to a class group (idempotent).
     * Supports:
     * - students.class_group_id
     * - or pivot table student_class_groups(student_id, class_group_id, academic_year, semester)
     */
    public function assignStudentToGroup(
        int $studentId,
        int $classGroupId,
        string $academicYear,
        int $semester
    ): void {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

        // Option A: students has class_group_id
        if (Schema::hasColumn('students', 'class_group_id')) {
            DB::table('students')
                ->where('id', $studentId)
                ->update([
                    'class_group_id' => $classGroupId,
                    'updated_at' => now(),
                ]);
            return;
        }

        // Option B: pivot table
        if (Schema::hasTable('student_class_groups')) {
            DB::table('student_class_groups')->updateOrInsert(
                [
                    'student_id' => $studentId,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                ],
                [
                    'class_group_id' => $classGroupId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            return;
        }

        // If neither exists, you MUST add one of them.
        // We silently do nothing to avoid breaking production.
    }

    /**
     * Count how many students are using a class group (for capacity check).
     */
    private function countStudentsInGroup(int $classGroupId, string $academicYear, int $semester): int
    {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

        // Option A: students.class_group_id
        if (Schema::hasColumn('students', 'class_group_id')) {
            // If you want to count only students in this academic period, join student_academic_periods
            if (Schema::hasTable('student_academic_periods')) {
                return (int) DB::table('students')
                    ->join('student_academic_periods as sap', 'sap.student_id', '=', 'students.id')
                    ->where('students.class_group_id', $classGroupId)
                    ->where('sap.academic_year', $academicYear)
                    ->where('sap.semester', $semester)
                    ->distinct('students.id')
                    ->count('students.id');
            }

            return (int) DB::table('students')
                ->where('class_group_id', $classGroupId)
                ->count();
        }

        // Option B: pivot
        if (Schema::hasTable('student_class_groups')) {
            return (int) DB::table('student_class_groups')
                ->where('class_group_id', $classGroupId)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->count();
        }

        return 0;
    }

    /**
     * Generate next class name automatically:
     * If existing names end with number => increment (e.g. "Class 1" => "Class 2")
     * Otherwise => "Class 1"
     */
    private function nextClassName(int $majorId, string $academicYear, int $semester, ?string $shift): string
    {
        $q = ClassGroup::query()
            ->where('major_id', $majorId)
            ->where('academic_year', $academicYear)
            ->where('semester', $semester);

        if ($shift !== null && $shift !== '') {
            $q->where('shift', $shift);
        } else {
            $q->where(function ($w) {
                $w->whereNull('shift')->orWhere('shift', '');
            });
        }

        $last = $q->orderByDesc('id')->first();

        if (!$last) {
            return 'Class 1';
        }

        $name = (string) $last->class_name;

        // find trailing number
        if (preg_match('/(\d+)\s*$/', $name, $m)) {
            $n = (int) $m[1];
            $next = $n + 1;
            $base = preg_replace('/(\d+)\s*$/', '', $name);
            $base = trim($base);
            if ($base === '') $base = 'Class';
            return $base . ' ' . $next;
        }

        // if no number, append 2
        return trim($name) . ' 2';
    }
}
