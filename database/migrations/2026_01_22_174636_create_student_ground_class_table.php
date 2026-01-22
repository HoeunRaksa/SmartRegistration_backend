<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_class_groups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('class_group_id')->constrained('class_groups')->cascadeOnDelete();

            // period info
            $table->string('academic_year', 20);
            $table->unsignedTinyInteger('semester'); // 1 or 2

            $table->timestamps();

            // ✅ prevent duplicate assignment same student/year/semester
            $table->unique(['student_id', 'academic_year', 'semester'], 'uniq_student_period_class');

            // helpful indexes
            $table->index(['class_group_id', 'academic_year', 'semester'], 'idx_class_period');
            $table->index(['student_id', 'academic_year', 'semester'], 'idx_student_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_class_groups');
    }
};
