<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\BackfillStudentAcademicPeriods;
Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Artisan::command('backfill:student-periods {--semester=1} {--status=ACTIVE}', function () {
    $this->call(BackfillStudentAcademicPeriods::class, [
        '--semester' => $this->option('semester'),
        '--status' => $this->option('status'),
    ]);
});
