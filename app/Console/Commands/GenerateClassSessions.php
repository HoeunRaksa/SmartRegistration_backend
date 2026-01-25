<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateClassSessions extends Command
{
    protected $signature = 'sessions:generate 
                            {--start= : Start date (Y-m-d)} 
                            {--end= : End date (Y-m-d)}
                            {--course= : Specific course ID}
                            {--overwrite : Overwrite existing sessions}';

    protected $description = 'Generate class sessions from schedules';

    public function handle()
    {
        // Get date range
        $startDate = $this->option('start') 
            ? Carbon::parse($this->option('start')) 
            : Carbon::now();
        
        $endDate = $this->option('end') 
            ? Carbon::parse($this->option('end')) 
            : Carbon::now()->addMonths(4); // One semester

        $overwrite = $this->option('overwrite');

        // Get schedules
        $query = ClassSchedule::with('course');
        
        if ($this->option('course')) {
            $query->where('course_id', $this->option('course'));
        }
        
        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            $this->error('❌ No schedules found!');
            return 1;
        }

        $this->info("🚀 Generating sessions from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");
        
        DB::beginTransaction();

        $totalGenerated = 0;
        $totalSkipped = 0;
        $bar = $this->output->createProgressBar($schedules->count());

        foreach ($schedules as $schedule) {
            $result = $this->generateSessionsForSchedule($schedule, $startDate, $endDate, $overwrite);
            $totalGenerated += $result['generated'];
            $totalSkipped += $result['skipped'];
            $bar->advance();
        }

        DB::commit();

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Generated: {$totalGenerated} sessions");
        $this->info("⏭️  Skipped: {$totalSkipped} existing sessions");

        return 0;
    }

    private function generateSessionsForSchedule($schedule, $startDate, $endDate, $overwrite = false)
    {
        $generated = 0;
        $skipped = 0;
        $current = $startDate->copy();

        $dayMap = [
            'Monday' => Carbon::MONDAY,
            'Tuesday' => Carbon::TUESDAY,
            'Wednesday' => Carbon::WEDNESDAY,
            'Thursday' => Carbon::THURSDAY,
            'Friday' => Carbon::FRIDAY,
            'Saturday' => Carbon::SATURDAY,
            'Sunday' => Carbon::SUNDAY,
        ];

        $targetDay = $dayMap[$schedule->day_of_week] ?? null;
        
        if (!$targetDay) {
            return ['generated' => 0, 'skipped' => 0];
        }

        // Move to first occurrence of the target day
        while ($current->dayOfWeek !== $targetDay) {
            $current->addDay();
        }

        // Generate sessions for each occurrence
        while ($current->lte($endDate)) {
            $sessionDate = $current->format('Y-m-d');

            $existing = ClassSession::where('course_id', $schedule->course_id)
                ->where('session_date', $sessionDate)
                ->where('start_time', $schedule->start_time)
                ->first();

            if ($existing) {
                if ($overwrite) {
                    $existing->update([
                        'end_time' => $schedule->end_time,
                        'session_type' => $schedule->session_type,
                        'room' => $schedule->room,
                    ]);
                    $generated++;
                } else {
                    $skipped++;
                }
            } else {
                ClassSession::create([
                    'course_id' => $schedule->course_id,
                    'session_date' => $sessionDate,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'session_type' => $schedule->session_type,
                    'room' => $schedule->room,
                ]);
                $generated++;
            }

            $current->addWeek();
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
        ];
    }
}