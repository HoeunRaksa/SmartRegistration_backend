<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_academic_periods', function (Blueprint $table) {
            $table->unique(
                ['student_id', 'academic_year', 'semester'],
                'uniq_student_year_sem'
            );
        });
    }

    public function down(): void
    {
        Schema::table('student_academic_periods', function (Blueprint $table) {
            $table->dropUnique('uniq_student_year_sem');
        });
    }
};
