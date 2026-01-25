<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            // Prevent duplicate enrollment
            $table->unique(
                ['course_id', 'student_id'],
                'ce_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropUnique('ce_unique');
        });
    }
};
