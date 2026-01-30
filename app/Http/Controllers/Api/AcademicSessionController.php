<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\ClassSession;
use Carbon\Carbon;

class AcademicSessionController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => AcademicSession::orderBy('start_date', 'desc')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'academic_year' => 'required|string',
            'semester' => 'required|string',
        ]);

        $session = AcademicSession::create($validated);
        return response()->json(['data' => $session], 201);
    }

    public function update(Request $request, $id)
    {
        $session = AcademicSession::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'academic_year' => 'sometimes|string',
            'semester' => 'sometimes|string',
        ]);

        $session->update($validated);
        return response()->json(['data' => $session]);
    }

    public function destroy($id)
    {
        AcademicSession::destroy($id);
        return response()->json(['success' => true]);
    }

    /**
     * Generate class sessions for all courses in this academic session
     */
    public function generateSchedules(Request $request, $id)
    {
        $session = AcademicSession::findOrFail($id);
        
        // 1. Find all courses matching this session's academic year and semester
        $courses = Course::with('classSchedules')
            ->where('academic_year', $session->academic_year)
            ->where('semester', $session->semester)
            ->get();

        if ($courses->isEmpty()) {
            return response()->json(['message' => "No courses found for this session.", 'count' => 0]);
        }

        $generatedCount = 0;
        $startDate = Carbon::parse($session->start_date)->startOfDay();
        $endDate = Carbon::parse($session->end_date)->endOfDay();
        // Fallback or ensure we don't loop forever
        if ($startDate->gt($endDate)) {
             return response()->json(['message' => "Start date cannot be after end date.", 'count' => 0], 400);
        }

        foreach ($courses as $course) {
            foreach ($course->classSchedules as $schedule) {
                // Map day name (Monday) to Carbon day of week integer
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
                if ($targetDay === null) continue;

                // Find the first occurrence of this day on or after start date
                $current = $startDate->copy();
                
                // If start date matches, use it. Otherwise find next.
                // Note: dayOfWeek returns 0 (Sun) - 6 (Sat) usually, but Carbon constants are 0-6 too.
                // However, let's play safe with a loop limited to 7 days
                $daysChecked = 0;
                while ($current->dayOfWeek !== $targetDay && $daysChecked < 7) {
                    $current->addDay();
                    $daysChecked++;
                }

                // If we went past end date just trying to find the first day, skip
                if ($current->gt($endDate)) continue;

                // Loop through dates (weekly)
                while ($current->lte($endDate)) {
                    ClassSession::firstOrCreate([
                        'course_id' => $course->id,
                        'session_date' => $current->format('Y-m-d'),
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                    ], [
                        'session_type' => $schedule->session_type ?? 'Lecture',
                        'room_id' => $schedule->room_id,
                        'room' => $schedule->room,
                    ]);
                    
                    $generatedCount++;
                    $current->addWeek();
                }
            }
        }

        return response()->json([
            'message' => "Successfully generated sessions.",
            'count' => $generatedCount
        ]);
    }

    /**
     * Get the current or upcoming academic session
     */
    public function current()
    {
        $today = Carbon::now();
        
        // 1. Try to find a session that covers today
        $current = AcademicSession::where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->first();

        // 2. If no current, find the next upcoming one
        if (!$current) {
            $current = AcademicSession::where('start_date', '>', $today)
                ->orderBy('start_date', 'asc')
                ->first();
        }

        // 3. Fallback to latest
        if (!$current) {
            $current = AcademicSession::orderBy('end_date', 'desc')->first();
        }

        return response()->json([
            'data' => $current
        ]);
    }
}
