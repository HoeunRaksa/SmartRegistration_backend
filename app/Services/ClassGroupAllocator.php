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
     * Priority:
     * 1) If table student_class_groups exists => use pivot (BEST long-life)
     * 2) Else if students.class_group_id exists => use column
     */
    public function getOrCreateAvailableGroup(
        int $majorId,
        string $academicYear,
        int $semester,
        ?string $shift = null,
        int $defaultCapacity = 40
    ): ClassGroup {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

        // 1) Find existing group with free seats
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

        $groups = $q->orderBy('id')->get();

        foreach ($groups as $g) {
            $capacity = (int) ($g->capacity ?? $defaultCapacity);
            if ($capacity <= 0) $capacity = $defaultCapacity;

            $used = $this->countStudentsInGroup((int)$g->id, $academicYear, $semester);

            if ($used < $capacity) {
                return $g;
            }
        }

        // 2) All full => create new group
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
     * If pivot exists => use pivot (recommended).
     * Else if students.class_group_id exists => use column.
     */
    public function assignStudentToGroup(
        int $studentId,
        int $classGroupId,
        string $academicYear,
        int $semester
    ): void {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

        // ✅ Best long-life: pivot table
        if (Schema::hasTable('student_class_groups')) {
            $updated = DB::table('student_class_groups')
                ->where('student_id', $studentId)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->update([
                    'class_group_id' => $classGroupId,
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                DB::table('student_class_groups')->insert([
                    'student_id' => $studentId,
                    'class_group_id' => $classGroupId,
                    'academic_year' => $academicYear,
                    'semester' => $semester,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return;
        }

        // Fallback: students.class_group_id
        if (Schema::hasColumn('students', 'class_group_id')) {
            DB::table('students')
                ->where('id', $studentId)
                ->update([
                    'class_group_id' => $classGroupId,
                    'updated_at' => now(),
                ]);
            return;
        }

        // If neither exists => do nothing (avoid crashing production)
    }

    /**
     * Count students in class group for that year/semester.
     */
    private function countStudentsInGroup(int $classGroupId, string $academicYear, int $semester): int
    {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

        // ✅ pivot table (accurate per year/semester)
        if (Schema::hasTable('student_class_groups')) {
            return (int) DB::table('student_class_groups')
                ->where('class_group_id', $classGroupId)
                ->where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->count();
        }

        // fallback
        if (Schema::hasColumn('students', 'class_group_id')) {
            return (int) DB::table('students')
                ->where('class_group_id', $classGroupId)
                ->count();
        }

        return 0;
    }

    /**
     * Next class name: Class 1 -> Class 2 -> ...
     */
    private function nextClassName(int $majorId, string $academicYear, int $semester, ?string $shift): string
    {
        $semester = in_array((int)$semester, [1, 2], true) ? (int)$semester : 1;

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

        if (!$last) return 'Class 1';

        $name = (string) $last->class_name;

        if (preg_match('/(\d+)\s*$/', $name, $m)) {
            $n = (int) $m[1];
            $base = trim(preg_replace('/(\d+)\s*$/', '', $name));
            if ($base === '') $base = 'Class';
            return $base . ' ' . ($n + 1);
        }

        return trim($name) . ' 2';
    }
}
