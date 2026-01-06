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
Schema::create('registrations', function (Blueprint $table) {
    $table->id();
    $table->string('first_name', 100);
    $table->string('last_name', 100);
    $table->string('full_name_kh', 100);
    $table->string('full_name_en', 100);
    $table->string('gender', 10)->default('Other');
    $table->date('date_of_birth');
    $table->string('address', 200)->nullable();
    $table->string('current_address', 200)->nullable();
    $table->string('phone_number', 20)->nullable();
    $table->string('personal_email', 150);

    $table->string('high_school_name', 100);
    $table->string('graduation_year', 4);
    $table->string('grade12_result', 10)->nullable();

    $table->foreignId('department_id')->constrained()->onDelete('cascade');
    $table->foreignId('major_id')->constrained()->onDelete('cascade');

    $table->string('faculty', 50);
    $table->string('shift', 10)->default('Morning');
    $table->string('batch', 20)->nullable();
    $table->string('academic_year', 10)->nullable();
    $table->string('profile_picture_path', 255)->nullable();

    $table->string('father_name', 100)->nullable();
    $table->date('fathers_date_of_birth')->nullable();
    $table->string('fathers_nationality', 20)->nullable();
    $table->string('fathers_job', 100)->nullable();
    $table->string('fathers_phone_number', 20)->nullable();

    $table->string('mother_name', 100)->nullable();
    $table->date('mother_date_of_birth')->nullable();
    $table->string('mother_nationality', 20)->nullable();
    $table->string('mothers_job', 100)->nullable();
    $table->string('mother_phone_number', 20)->nullable();

    $table->string('guardian_name', 100)->nullable();
    $table->string('guardian_phone_number', 20)->nullable();
    $table->string('emergency_contact_name', 100)->nullable();
    $table->string('emergency_contact_phone_number', 20)->nullable();

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
