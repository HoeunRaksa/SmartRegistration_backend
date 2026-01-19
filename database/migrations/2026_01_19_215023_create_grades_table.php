<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->onDelete('cascade');
            $table->foreignId('course_id')->constrained()->onDelete('cascade');

            $table->string('assignment_name');
            $table->decimal('score', 6, 2);
            $table->decimal('total_points', 6, 2);
            $table->string('letter_grade')->nullable();
            $table->decimal('grade_point', 4, 2)->nullable(); // e.g. 4.00
            $table->text('feedback')->nullable();

            $table->timestamps();

            $table->index(['student_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
