<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillStudentAcademicPeriods extends Command
{
    protected $signature = 'backfill:student-periods {--semester=1} {--status=ACTIVE}';
    protected $description = 'Backfill student_academic_periods from existing students + registrations (safe, idempotent).';

    public function handle()
    {
        $semester = (int) $this->option('semester');
        if (!in_array($semester, [1, 2], true)) {
            $this->error("Invalid --semester value. Allowed: 1 or 2");
            return Command::FAILURE;
        }

        $status = strtoupper((string) $this->option('status'));
        if (!in_array($status, ['ACTIVE', 'COMPLETED', 'DROPPED'], true)) {
            $this->error("Invalid --status value. Allowed: ACTIVE, COMPLETED, DROPPED");
            return Command::FAILURE;
        }

        $this->info("== Backfill student_academic_periods ==");
        $this->info("semester={$semester}, status={$status}");

        // Ensure table exists
        if (!DB::getSchemaBuilder()->hasTable('student_academic_periods')) {
            $this->error("Table 'student_academic_periods' not found. Run migration first.");
            return Command::FAILURE;
        }

        $hasAmount = Schema::hasColumn('registrations', 'payment_amount');
        $hasStatus = Schema::hasColumn('registrations', 'payment_status');
        $hasDate   = Schema::hasColumn('registrations', 'payment_date');

        $rows = DB::table('students')
            ->join('registrations', 'students.registration_id', '=', 'registrations.id')
            ->select(
                'students.id as student_id',
                'registrations.academic_year',
                DB::raw($hasAmount ? 'registrations.payment_amount as payment_amount' : '0 as payment_amount'),
                DB::raw($hasStatus ? 'registrations.payment_status as payment_status' : "'PENDING' as payment_status"),
                DB::raw($hasDate ? 'registrations.payment_date as payment_date' : 'NULL as payment_date')
            )
            ->orderBy('students.id')
            ->get();

        $created = 0;
        $skipped = 0;
        $errors  = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $r) {

                // academic_year required (your registration controller requires it now)
                $academicYear = trim((string) ($r->academic_year ?? ''));
                if ($academicYear === '') {
                    $skipped++;
                    continue;
                }

                // Normalize payment status
                $paymentStatus = strtoupper((string) ($r->payment_status ?? 'PENDING'));
                if (!in_array($paymentStatus, ['PENDING', 'PAID', 'PARTIAL'], true)) {
                    // map old values if any
                    if (in_array($paymentStatus, ['SUCCESS', 'DONE', 'COMPLETED'], true)) {
                        $paymentStatus = 'PAID';
                    } else {
                        $paymentStatus = 'PENDING';
                    }
                }

                // paid_at only when PAID (or partial if you want; keep safe)
                $paidAt = null;
                if ($paymentStatus === 'PAID') {
                    $paidAt = $r->payment_date ?: null;
                }

                // Skip if already exists
                $exists = DB::table('student_academic_periods')
                    ->where('student_id', $r->student_id)
                    ->where('academic_year', $academicYear)
                    ->where('semester', $semester)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                DB::table('student_academic_periods')->insert([
                    'student_id'     => $r->student_id,
                    'academic_year'  => $academicYear,
                    'semester'       => $semester,
                    'status'         => $status,
                    'tuition_amount' => $r->payment_amount ?? 0,
                    'payment_status' => $paymentStatus,
                    'paid_at'        => $paidAt,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                $created++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors++;
            $this->error("Backfill failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info("== DONE ==");
        $this->info("created={$created}");
        $this->info("skipped={$skipped}");
        $this->info("errors={$errors}");

        return Command::SUCCESS;
    }
}
