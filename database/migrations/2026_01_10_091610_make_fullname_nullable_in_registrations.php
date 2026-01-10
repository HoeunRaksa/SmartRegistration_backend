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
        Schema::table('registrations', function (Blueprint $table) {
            // Make full_name_en and full_name_kh nullable
            $table->string('full_name_en')->nullable()->change();
            $table->string('full_name_kh')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('full_name_en')->nullable(false)->change();
            $table->string('full_name_kh')->nullable(false)->change();
        });
    }
};