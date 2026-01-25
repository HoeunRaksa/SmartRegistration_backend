<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\BackfillStudentAcademicPeriods;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// ==========================================
// ARTISAN COMMANDS
// ==========================================

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('backfill:student-periods {--semester=1} {--status=ACTIVE}', function () {
    $this->call(BackfillStudentAcademicPeriods::class, [
        '--semester' => $this->option('semester'),
        '--status' => $this->option('status'),
    ]);
})->purpose('Backfill student academic periods');

// ==========================================
// SCHEDULED TASKS
// ==========================================

/*
|--------------------------------------------------------------------------
| Class Session Generation Schedule
|--------------------------------------------------------------------------
| Automatically generate class sessions from schedules
*/

// 🎓 RECOMMENDED: Generate at semester start (Cambodian academic calendar)
// Semester 1: October - February
// Semester 2: March - July
Schedule::command('sessions:generate', [
    '--start' => 'now',
    '--end' => '+5months'
])
    ->cron('0 2 15 3,10 *')  // March 15 & October 15 at 2:00 AM
    ->timezone('Asia/Phnom_Penh')
    ->name('semester-session-generation')
    ->withoutOverlapping()
    ->onSuccess(function () {
        info('✅ Semester sessions generated successfully');
    })
    ->onFailure(function () {
        error('❌ Failed to generate semester sessions');
    });

// 🔄 SAFETY NET: Generate upcoming sessions weekly
Schedule::command('sessions:generate', [
    '--start' => 'now',
    '--end' => '+2weeks'
])
    ->weekly()
    ->sundays()
    ->at('01:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('weekly-session-generation')
    ->withoutOverlapping()
    ->onSuccess(function () {
        info('✅ Weekly sessions generated successfully');
    });

// 📊 OPTIONAL: Monthly generation (if you prefer monthly)
// Uncomment if you want monthly generation instead of semester-based
/*
Schedule::command('sessions:generate', [
    '--start' => 'now',
    '--end' => '+1month'
])
    ->monthly()
    ->at('00:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('monthly-session-generation')
    ->withoutOverlapping();
*/

// 🗓️ OPTIONAL: Quarterly generation (every 4 months)
// Uncomment if you prefer quarterly generation
/*
Schedule::command('sessions:generate', [
    '--start' => 'now',
    '--end' => '+4months'
])
    ->quarterly()
    ->at('00:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('quarterly-session-generation')
    ->withoutOverlapping();
*/

/*
|--------------------------------------------------------------------------
| Database Maintenance
|--------------------------------------------------------------------------
*/

// Clean up old sessions (older than 2 years)
Schedule::command('sessions:cleanup')
    ->yearly()
    ->at('03:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('yearly-session-cleanup');

// Backup database daily
Schedule::command('backup:run --only-db')
    ->daily()
    ->at('02:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('daily-database-backup');

/*
|--------------------------------------------------------------------------
| Log Cleanup
|--------------------------------------------------------------------------
*/

// Clear old logs (keep last 30 days)
Schedule::command('logs:clear')
    ->daily()
    ->at('04:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('daily-log-cleanup');

/*
|--------------------------------------------------------------------------
| System Health Checks
|--------------------------------------------------------------------------
*/

// Check system health daily
Schedule::call(function () {
    // Add your health check logic here
    info('System health check completed');
})
    ->daily()
    ->at('06:00')
    ->timezone('Asia/Phnom_Penh')
    ->name('daily-health-check');