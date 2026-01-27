<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Remove duplicates first (IMPORTANT) so unique index can be created
        // Keeps the smallest id row, deletes others for same (student_id, course_id)
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                DELETE ce1 FROM course_enrollments ce1
                INNER JOIN course_enrollments ce2
                WHERE
                    ce1.id > ce2.id
                    AND ce1.student_id = ce2.student_id
                    AND ce1.course_id = ce2.course_id
            ");
        } else if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                DELETE FROM course_enrollments 
                WHERE id NOT IN (
                    SELECT MIN(id) 
                    FROM course_enrollments 
                    GROUP BY student_id, course_id
                )
            ");
        }

        // 2) Add unique index
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->unique(['student_id', 'course_id'], 'uq_course_enrollments_student_course');
        });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropUnique('uq_course_enrollments_student_course');
        });
    }
};
