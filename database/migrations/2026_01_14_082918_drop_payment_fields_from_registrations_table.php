<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            // MUST drop foreign key first
            $table->dropForeign(['payment_tran_id']);

            // Drop payment-related columns
            $table->dropColumn([
                'payment_tran_id',
                'payment_status',
                'payment_amount',
                'payment_date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->string('payment_tran_id')->nullable();
            $table->string('payment_status')->default('PENDING');
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->timestamp('payment_date')->nullable();

            $table->foreign('payment_tran_id')
                  ->references('tran_id')
                  ->on('payment_transactions')
                  ->onDelete('set null');
        });
    }
};
