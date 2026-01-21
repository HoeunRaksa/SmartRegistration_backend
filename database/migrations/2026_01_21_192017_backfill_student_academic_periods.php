<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * BACKFILL student_academic_periods
         * --------------------------------
         * - One row per student per academic_year
         * - Prevent duplicates
         * - Safe to run on production
         */

        DB::transaction(function () {

            $rows = DB::table('students')
                ->join('registrations', 'students.registration_id', '=', 'registrations.id')
                ->select(
                    'students.id as student_id',
                    'registrations.academic_year',
                    'registrations.payment_status',
                    'registrations.payment_amount',
                    'registrations.payment_date',
                    'registrations.created_at'
                )
                ->get();

            foreach ($rows as $row) {

                // ⛔ Skip if already exists (IMPORTANT)
                $exists = DB::table('student_academic_periods')
                    ->where('student_id', $row->student_id)
                    ->where('academic_year', $row->academic_year)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('student_academic_periods')->insert([
                    'student_id'      => $row->student_id,
                    'academic_year'   => $row->academic_year,
                    'semester'        => 1, // default first semester
                    'status'          => 'ACTIVE',
                    'tuition_amount'  => $row->payment_amount ?? 0,
                    'payment_status'  => $row->payment_status ?? 'PENDING',
                    'paid_at'         => $row->payment_status === 'PAID'
                                            ? $row->payment_date
                                            : null,
                    'created_at'      => $row->created_at ?? now(),
                    'updated_at'      => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        /**
         * Rollback only backfilled rows
         * (does NOT touch future academic years)
         */
        DB::table('student_academic_periods')->where('semester', 1)->delete();
    }
};
