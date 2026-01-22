<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('major_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('major_id')->constrained('majors')->onDelete('cascade');
            $table->string('academic_year', 20); // "2026-2027"
            $table->unsignedInteger('limit');    // required (no null). If you want unlimited, just don't create a quota row.
            $table->timestamps();

            $table->unique(['major_id', 'academic_year'], 'uq_major_year_quota');
            $table->index(['major_id', 'academic_year'], 'idx_major_year_quota');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('major_quotas');
    }
};
