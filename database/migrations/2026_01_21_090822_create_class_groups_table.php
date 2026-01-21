<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();

            $table->string('class_name', 20); // A3, A4

            $table->foreignId('major_id')
                ->constrained()
                ->onDelete('cascade');

            // MUST match courses.academic_year (string)
            $table->string('academic_year', 20); // e.g. 2025-2026
            $table->unsignedTinyInteger('semester')->nullable(); // 1,2
            $table->string('shift', 20)->nullable(); // Morning / Evening

            $table->unsignedInteger('capacity')->default(0);

            $table->timestamps();

            $table->unique(
                ['class_name', 'major_id', 'academic_year', 'semester', 'shift'],
                'class_groups_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_groups');
    }
};
