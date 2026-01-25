<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_schedules', function (Blueprint $table) {
            // Add room_id column
            $table->foreignId('room_id')->nullable()->after('session_type')->constrained()->onDelete('set null');
            
            // Keep room column for backward compatibility (or remove if not needed)
            // If you want to migrate data: update room_id based on room, then drop room column
        });
    }

    public function down(): void
    {
        Schema::table('class_schedules', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropColumn('room_id');
        });
    }
};