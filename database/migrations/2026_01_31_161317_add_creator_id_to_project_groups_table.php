<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('creator_id')->nullable()->after('teacher_id');
            // We can't easily constrain to users because it could be student or teacher
            // but usually it's the user who created it
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_groups', function (Blueprint $table) {
            $table->dropColumn('creator_id');
        });
    }
};
