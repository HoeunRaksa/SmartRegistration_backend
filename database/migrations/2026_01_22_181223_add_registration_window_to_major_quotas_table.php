<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('major_quotas', function (Blueprint $table) {
            $table->timestamp('opens_at')->nullable()->after('limit');
            $table->timestamp('closes_at')->nullable()->after('opens_at');

            $table->index(['opens_at', 'closes_at'], 'idx_major_quota_window');
        });
    }

    public function down(): void
    {
        Schema::table('major_quotas', function (Blueprint $table) {
            $table->dropIndex('idx_major_quota_window');
            $table->dropColumn(['opens_at', 'closes_at']);
        });
    }
};
