<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration {
    public function up(): void
    {
        // If major_subjects does not exist, create it
        if (!Schema::hasTable('major_subjects')) {
            Schema::create('major_subjects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('major_id')->constrained('majors')->onDelete('cascade');
                $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // SAFE rollback for THIS fix migration only
        Schema::dropIfExists('major_subjects');
    }
};
