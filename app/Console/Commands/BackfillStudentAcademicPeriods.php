<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillStudentAcademicPeriods extends Command
{
    protected $signature = 'backfill:student-periods 
    {--semester=1} 
    {--status=ACTIVE} 
    {--dry-run}';
    protected $description = 'Backfill student_academic_periods from registrations (supports old+new flow).';

    public function handle()
    {
        $defaultSemester = (int) $this->option('semester');
        if (!in_array($defaultSemester, [1, 2], true)) {
            $this->error("Invalid --semester value. Allowed: 1 or 2");
            return Command::FAILURE;
        }

        $status = strtoupper((string) $this->option('status'));
        if (!in_array($status, ['ACTIVE', 'COMPLETED', 'DROPPED'], true)) {
            $this->error("Invalid --status value. Allowed: ACTIVE, COMPLETED, DROPPED");
            return Command::FAILURE;
        }

        if (!DB::getSchemaBuilder()->hasTable('student_academic_periods')) {
            $this->error("Table 'student_academic_periods' not found. Run migration first.");
            return Command::FAILURE;
        }

        $hasRegSemester = Schema::hasColumn('registrations', 'semester');
        $hasPayStatus   = Schema::hasColumn('registrations', 'payment_status');
        $hasPayDate     = Schema::hasColumn('registrations', 'payment_date');
        $hasPayAmount   = Schema::hasColumn('registrations', 'payment_amount');

        $dryRun = (bool) $this->option('dry-run');

        $this->info("== Backfill student_academic_periods ==");
        $this->info("defaultSemester={$defaultSemester}, status={$status}, dryRun=" . ($dryRun ? 'YES' : 'NO'));

        $created = 0;
        $updated = 0;
        $skipped = 0;

        DB::table('registrations')
            ->leftJoin('users', 'users.email', '=', 'registrations.personal_email')
            ->leftJoin('students', function ($join) {
                $join->on('students.registration_id', '=', 'registrations.id')
                    ->orOn('students.user_id', '=', 'users.id');
            })
            ->leftJoin('majors', 'majors.id', '=', 'registrations.major_id')
            ->select(
                'registrations.id as registration_id',
                'registrations.academic_year',
                $hasRegSemester ? 'registrations.semester as reg_semester' : DB::raw('NULL as reg_semester'),
                $hasPayStatus ? 'registrations.payment_status as reg_payment_status' : DB::raw("'PENDING' as reg_payment_status"),
                $hasPayDate ? 'registrations.payment_date as reg_payment_date' : DB::raw('NULL as reg_payment_date'),
                $hasPayAmount ? 'registrations.payment_amount as reg_payment_amount' : DB::raw('NULL as reg_payment_amount'),
                'students.id as student_id',
                'majors.registration_fee as major_fee'
            )
            ->whereNotNull('students.id') // must map to a student
            ->orderBy('registrations.id')
            ->chunkById(500, function ($rows) use (
                $defaultSemester,
                $status,
                $dryRun,
                &$created,
                &$updated,
                &$skipped
            ) {
                foreach ($rows as $r) {
                    $academicYear = trim((string) ($r->academic_year ?? ''));
                    if ($academicYear === '') {
                        $skipped++;
                        continue;
                    }

                    // semester: prefer registrations.semester if exists, else default
                    $semester = (int) ($r->reg_semester ?? $defaultSemester);
                    if (!in_array($semester, [1, 2], true)) $semester = $defaultSemester;

                    // payment status (old values mapping)
                    $paymentStatus = strtoupper((string) ($r->reg_payment_status ?? 'PENDING'));
                    if (in_array($paymentStatus, ['SUCCESS', 'DONE', 'COMPLETED'], true)) $paymentStatus = 'PAID';
                    if (!in_array($paymentStatus, ['PENDING', 'PAID', 'PARTIAL'], true)) $paymentStatus = 'PENDING';

                    $paidAt = null;
                    if ($paymentStatus === 'PAID') {
                        $paidAt = $r->reg_payment_date ?: null;
                    }

                    // tuition: prefer major fee, else fallback to payment_amount, else 0
                    $tuition = (float) ($r->major_fee ?? ($r->reg_payment_amount ?? 0));

                    $where = [
                        'student_id'    => $r->student_id,
                        'academic_year' => $academicYear,
                        'semester'      => $semester,
                    ];

                    $data = [
                        'status'         => $status,
                        'tuition_amount' => $tuition,
                        'payment_status' => $paymentStatus,
                        'paid_at'        => $paidAt,
                        'updated_at'     => now(),
                    ];

                    if ($dryRun) {
                        // just count as "would write"
                        $skipped++;
                        continue;
                    }

                    // idempotent upsert
                    $exists = DB::table('student_academic_periods')->where($where)->exists();

                    DB::table('student_academic_periods')->updateOrInsert(
                        $where,
                        array_merge($data, $exists ? [] : ['created_at' => now()])
                    );

                    if ($exists) $updated++;
                    else $created++;
                }
            }, 'registrations.id');

        $this->info("== DONE ==");
        $this->info("created={$created}");
        $this->info("updated={$updated}");
        $this->info("skipped={$skipped}");

        return Command::SUCCESS;
    }
}
