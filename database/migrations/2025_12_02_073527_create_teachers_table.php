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
Schema::create('teachers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->foreignId('department_id')->constrained()->onDelete('cascade');

    $table->string('full_name', 100);
    $table->string('full_name_kh', 100);
    $table->string('gender', 10)->nullable();
    $table->date('date_of_birth')->nullable();
    $table->string('address', 255)->nullable();
    $table->string('phone_number', 15)->nullable();

    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
