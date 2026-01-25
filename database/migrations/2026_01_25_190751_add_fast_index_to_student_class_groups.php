<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('student_class_groups', function (Blueprint $table) {
            // FAST filter: class group + academic year + semester
            $table->index(
                ['class_group_id', 'academic_year', 'semester', 'student_id'],
                'scg_fast'
            );
        });
    }

    public function down(): void
    {
        Schema::table('student_class_groups', function (Blueprint $table) {
            $table->dropIndex('scg_fast');
        });
    }
};
