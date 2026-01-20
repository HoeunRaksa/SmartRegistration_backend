<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('major_subjects', function (Blueprint $table) {
            // curriculum structure
            $table->unsignedTinyInteger('year_level')
                ->default(1)
                ->after('subject_id');

            $table->unsignedTinyInteger('semester')
                ->default(1)
                ->after('year_level');

            $table->boolean('is_required')
                ->default(true)
                ->after('semester');
        });
    }

    public function down(): void
    {
        Schema::table('major_subjects', function (Blueprint $table) {
            $table->dropColumn([
                'year_level',
                'semester',
                'is_required',
            ]);
        });
    }
};
