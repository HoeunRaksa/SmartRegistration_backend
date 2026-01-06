<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('major_subject_id')->constrained()->onDelete('cascade');
    $table->foreignId('teacher_id')->constrained()->onDelete('cascade');
    $table->integer('semester');
    $table->integer('academic_year');
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
