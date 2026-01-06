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
       Schema::create('departments', function (Blueprint $table) {
    $table->id();
    $table->string('name', 100);
    $table->string('code', 20)->nullable();
    $table->string('faculty', 100)->nullable();
    $table->string('title', 255)->nullable();
    $table->string('description', 1000)->nullable();
    $table->string('contact_email', 255)->nullable();
    $table->string('phone_number', 20)->nullable();
    $table->string('image_path', 255)->nullable();
    $table->timestamps();
    });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
