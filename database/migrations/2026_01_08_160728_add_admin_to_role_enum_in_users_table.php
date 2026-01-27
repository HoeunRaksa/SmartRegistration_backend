<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE users 
            MODIFY role ENUM('admin','student','teacher','staff') 
            DEFAULT 'student'
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE users 
            MODIFY role ENUM('student','teacher','staff') 
            DEFAULT 'student'
        ");
    }
};

