<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_academic_periods', function (Blueprint $table) {
            if (!Schema::hasColumn('student_academic_periods', 'tran_id')) {
                $table->string('tran_id', 100)
                    ->nullable()
                    ->after('payment_status')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_academic_periods', function (Blueprint $table) {
            if (Schema::hasColumn('student_academic_periods', 'tran_id')) {
                $table->dropIndex(['tran_id']);
                $table->dropColumn('tran_id');
            }
        });
    }
};
