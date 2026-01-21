<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
{
    Schema::table('registrations', function (Blueprint $table) {

        if (Schema::hasColumn('registrations', 'payment_tran_id')) {
            $table->dropColumn('payment_tran_id');
        }

        if (Schema::hasColumn('registrations', 'payment_amount')) {
            $table->dropColumn('payment_amount');
        }

        if (Schema::hasColumn('registrations', 'payment_status')) {
            $table->dropColumn('payment_status');
        }

        if (Schema::hasColumn('registrations', 'payment_date')) {
            $table->dropColumn('payment_date');
        }
    });
}


    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('registrations', 'payment_amount')) {
                $table->decimal('payment_amount', 10, 2)->nullable();
            }

            if (!Schema::hasColumn('registrations', 'payment_status')) {
                $table->string('payment_status')->nullable();
            }

            if (!Schema::hasColumn('registrations', 'payment_date')) {
                $table->timestamp('payment_date')->nullable();
            }

            if (!Schema::hasColumn('registrations', 'payment_tran_id')) {
                $table->string('payment_tran_id')->nullable();
            }
        });
    }
};
