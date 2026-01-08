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
Schema::create('staffs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('user_name', 100);
    $table->foreignId('department_id')->nullable()->constrained()->onDelete('set null');
    $table->string('department_name', 100)->nullable();
    $table->string('full_name', 100);
    $table->string('full_name_kh', 100);
    $table->string('position', 50)->nullable();
    $table->string('email', 100)->nullable();
    $table->string('phone_number', 15)->nullable();
    $table->string('address', 255)->nullable();
    $table->string('gender', 10)->nullable();
    $table->date('date_of_birth')->nullable();
    $table->timestamps();
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staffs');
    }
};
