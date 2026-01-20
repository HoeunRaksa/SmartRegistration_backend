<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('major_subjects', function (Blueprint $table) {
            if (!Schema::hasColumn('major_subjects', 'year_level')) {
                $table->unsignedTinyInteger('year_level')->nullable()->after('subject_id');
            }
            if (!Schema::hasColumn('major_subjects', 'semester')) {
                $table->string('semester', 20)->nullable()->after('year_level');
            }
            if (!Schema::hasColumn('major_subjects', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('semester');
            }

            // ✅ important: prevent duplicates
            $table->unique(['major_id', 'subject_id'], 'major_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::table('major_subjects', function (Blueprint $table) {
            $table->dropUnique('major_subject_unique');
            $table->dropColumn(['year_level', 'semester', 'is_required']);
        });
    }
};
