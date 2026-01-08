<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Convert users table to utf8mb4
        DB::statement('ALTER TABLE users CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        // Convert staffs table to utf8mb4
        DB::statement('ALTER TABLE staffs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        // Specifically fix problematic columns
        DB::statement('ALTER TABLE staffs MODIFY full_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE staffs MODIFY full_name_kh VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        DB::statement('ALTER TABLE users MODIFY name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to utf8 if needed (optional)
        DB::statement('ALTER TABLE users CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci');
        DB::statement('ALTER TABLE staffs CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci');
    }
};