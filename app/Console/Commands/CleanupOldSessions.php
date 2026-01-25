<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ClassSession;
use Carbon\Carbon;

class CleanupOldSessions extends Command
{
    protected $signature = 'sessions:cleanup {--years=2 : Keep sessions from last N years}';
    protected $description = 'Clean up old class sessions';

    public function handle()
    {
        $years = $this->option('years');
        $cutoffDate = Carbon::now()->subYears($years);

        $this->info("🗑️  Cleaning up sessions older than {$cutoffDate->format('Y-m-d')}...");

        // Only delete sessions without attendance records
        $deleted = ClassSession::where('session_date', '<', $cutoffDate)
            ->doesntHave('attendanceRecords')
            ->delete();

        $this->info("✅ Deleted {$deleted} old sessions");

        return 0;
    }
}