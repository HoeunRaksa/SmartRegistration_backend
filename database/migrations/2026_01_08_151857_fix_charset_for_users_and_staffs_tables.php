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
        try {
            $driver = strtolower(DB::getDriverName());
            if ($driver !== 'mysql') {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

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
        try {
            if (strtolower(DB::getDriverName()) !== 'mysql') {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        // Revert back to utf8 if needed (optional)
        DB::statement('ALTER TABLE users CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci');
        DB::statement('ALTER TABLE staffs CONVERT TO CHARACTER SET utf8 COLLATE utf8_unicode_ci');
    }
};
