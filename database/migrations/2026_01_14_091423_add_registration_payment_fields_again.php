<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('payment_tran_id')->nullable()->after('emergency_contact_phone_number');

            $table->enum('payment_status', ['PENDING', 'PAID', 'FAILED'])
                  ->default('PENDING')
                  ->after('payment_tran_id');

            $table->decimal('payment_amount', 10, 2)
                  ->nullable()
                  ->after('payment_status');

            $table->timestamp('payment_date')
                  ->nullable()
                  ->after('payment_amount');

            $table->foreign('payment_tran_id')
                  ->references('tran_id')
                  ->on('payment_transactions')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['payment_tran_id']);
            $table->dropColumn([
                'payment_tran_id',
                'payment_status',
                'payment_amount',
                'payment_date',
            ]);
        });
    }
};
