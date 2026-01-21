<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

public function up(): void
{
    // ✅ Skip if already exists (prevents duplicate column error)
    if (!Schema::hasColumn('courses', 'class_group_id')) {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('class_group_id')
                ->nullable()
                ->after('major_subject_id') // adjust if needed
                ->constrained('class_groups')
                ->nullOnDelete();
        });
    }

    // optional index (also safe)
    Schema::table('courses', function (Blueprint $table) {
        // create index only if not exists is not built-in, so keep simple:
        // If you already have an index, you can remove this to avoid duplicate index error.
        // $table->index(['class_group_id', 'academic_year'], 'courses_classgroup_year_idx');
    });
}

public function down(): void
{
    // ✅ Only drop if exists
    if (Schema::hasColumn('courses', 'class_group_id')) {
        Schema::table('courses', function (Blueprint $table) {
            // drop FK if it exists (Laravel expects default name)
            try { $table->dropForeign(['class_group_id']); } catch (\Throwable $e) {}
            $table->dropColumn('class_group_id');
        });
    }
}

};
