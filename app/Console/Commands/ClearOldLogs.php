<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

class ClearOldLogs extends Command
{
    protected $signature = 'logs:clear {--days=30 : Keep logs from last N days}';
    protected $description = 'Clear old log files';

    public function handle()
    {
        $days = $this->option('days');
        $logPath = storage_path('logs');
        $cutoffDate = Carbon::now()->subDays($days);

        $this->info("🗑️  Clearing logs older than {$days} days...");

        $files = File::files($logPath);
        $deleted = 0;

        foreach ($files as $file) {
            $fileDate = Carbon::createFromTimestamp(File::lastModified($file));
            
            if ($fileDate->lt($cutoffDate) && $file->getFilename() !== '.gitignore') {
                File::delete($file);
                $deleted++;
            }
        }

        $this->info("✅ Deleted {$deleted} old log files");

        return 0;
    }
}