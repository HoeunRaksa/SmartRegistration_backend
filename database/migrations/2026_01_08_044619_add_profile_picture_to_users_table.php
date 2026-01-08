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
    Schema::table('users', function ($table) {
        $table->string('profile_picture_path')->nullable()->after('password');
    });
}

public function down(): void
{
    Schema::table('users', function ($table) {
        $table->dropColumn('profile_picture_path');
    });
}


};
