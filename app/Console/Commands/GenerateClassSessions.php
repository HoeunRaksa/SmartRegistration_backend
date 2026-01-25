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
        $startDate = $this->option('start')
            ? Carbon::parse($this->option('start'))->startOfDay()
            : Carbon::now()->startOfDay();

        $endDate = $this->option('end')
            ? Carbon::parse($this->option('end'))->endOfDay()
            : Carbon::now()->addMonths(4)->endOfDay();

        $overwrite = (bool) $this->option('overwrite');

        $query = ClassSchedule::query();

        if ($this->option('course')) {
            $query->where('course_id', (int) $this->option('course'));
        }

        $schedules = $query->get();

        if ($schedules->isEmpty()) {
            $this->error('❌ No schedules found!');
            return 1;
        }

        $this->info("🚀 Generating sessions from {$startDate->format('Y-m-d')} to {$endDate->format('Y-m-d')}");

        $totalGenerated = 0;
        $totalSkipped = 0;

        DB::beginTransaction();

        try {
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
            $this->info("✅ Generated/Updated: {$totalGenerated} sessions");
            $this->info("⏭️  Skipped: {$totalSkipped} existing sessions");

            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('❌ Failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function generateSessionsForSchedule($schedule, Carbon $startDate, Carbon $endDate, bool $overwrite = false): array
    {
        $generated = 0;
        $skipped = 0;

        $dayMap = [
            'Monday'    => Carbon::MONDAY,
            'Tuesday'   => Carbon::TUESDAY,
            'Wednesday' => Carbon::WEDNESDAY,
            'Thursday'  => Carbon::THURSDAY,
            'Friday'    => Carbon::FRIDAY,
            'Saturday'  => Carbon::SATURDAY,
            'Sunday'    => Carbon::SUNDAY,
        ];

        $targetDay = $dayMap[$schedule->day_of_week] ?? null;
        if ($targetDay === null) {
            return ['generated' => 0, 'skipped' => 0];
        }

        $current = $startDate->copy();

        // Move to first occurrence of the target day
        while ($current->dayOfWeek !== $targetDay) {
            $current->addDay();
        }

        // Generate sessions for each occurrence
        while ($current->lte($endDate)) {
            $sessionDate = $current->toDateString();

            // Stronger matching:
            // course_id + session_date + start_time + (room_id if present, else legacy room string)
            $existingQuery = ClassSession::query()
                ->where('course_id', $schedule->course_id)
                ->where('session_date', $sessionDate)
                ->where('start_time', $schedule->start_time);

            if (!is_null($schedule->room_id)) {
                $existingQuery->where('room_id', $schedule->room_id);
            } else {
                $existingQuery->where('room', $schedule->room);
            }

            $existing = $existingQuery->first();

            $payload = [
                'course_id'    => $schedule->course_id,
                'session_date' => $sessionDate,
                'start_time'   => $schedule->start_time,
                'end_time'     => $schedule->end_time,
                'session_type' => $schedule->session_type,

                // Keep both for compatibility (requires columns/fillable on ClassSession)
                'room'         => $schedule->room,
                'room_id'      => $schedule->room_id,
            ];

            if ($existing) {
                if ($overwrite) {
                    $existing->update($payload);
                    $generated++;
                } else {
                    $skipped++;
                }
            } else {
                ClassSession::create($payload);
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
