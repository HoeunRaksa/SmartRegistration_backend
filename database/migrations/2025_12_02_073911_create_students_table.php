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
Schema::create('students', function (Blueprint $table) {
    $table->id();
    $table->string('student_code', 50)->unique();

    // Foreign keys
    $table->foreignId('registration_id')->constrained('registrations')->onDelete('cascade');
    $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
    $table->foreignId('department_id')->constrained('departments')->onDelete('cascade');

    $table->string('full_name_kh', 100)->nullable();
    $table->string('full_name_en', 100)->nullable();
    $table->date('date_of_birth')->nullable();
    $table->string('gender', 10)->nullable();
    $table->string('nationality', 20)->nullable();
    $table->string('phone_number', 20)->nullable();
    $table->string('address', 200)->nullable();
    $table->integer('generation')->default(1);
    $table->string('parent_name', 100)->nullable();
    $table->string('parent_phone', 20)->nullable();

    $table->timestamps();
});


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
