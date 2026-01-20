<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentScheduleController extends Controller
{
    private function mapScheduleItem($item)
    {
        $course = $item->course;

        // Simple color mapping by course_id (stable)
        $colors = [
            'from-blue-500 to-cyan-500',
            'from-purple-500 to-pink-500',
            'from-green-500 to-emerald-500',
            'from-orange-500 to-red-500',
            'from-indigo-500 to-purple-500',
            'from-teal-500 to-green-500',
        ];

        $color = $colors[($item->course_id ?? 0) % count($colors)];

        return [
            'id' => $item->id,
            'course_id' => $item->course_id,

            // ✅ fields your frontend uses
            'course_code' => $course->course_code ?? null,
            'course_name' => $course->course_name ?? null,
            'instructor'  => $course->instructor ?? ($course->teacher ?? null),

            'day' => $item->day_of_week, // frontend expects "day"
            'day_of_week' => $item->day_of_week, // keep also original just in case

            'start_time' => substr((string)$item->start_time, 0, 5),
            'end_time'   => substr((string)$item->end_time, 0, 5),
            'room'       => $item->room,
            'session_type' => $item->session_type,

            'color' => $color,
        ];
    }

    public function getSchedule(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->orderByRaw("FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
                ->orderBy('start_time')
                ->get()
                ->map(fn($item) => $this->mapScheduleItem($item));

            return response()->json(['data' => $schedule], 200);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@getSchedule error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load schedule'], 500);
        }
    }

    public function getTodaySchedule(Request $request)
    {
        try {
            $today = now()->format('l'); // Monday, Tuesday...

            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->where('day_of_week', $today)
                ->orderBy('start_time')
                ->get()
                ->map(fn($item) => $this->mapScheduleItem($item));

            return response()->json(['data' => $schedule], 200);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@getTodaySchedule error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load today schedule'], 500);
        }
    }

    // ✅ NEW: /student/schedule/week?start_date=YYYY-MM-DD
    public function getWeekSchedule(Request $request)
    {
        try {
            $request->validate([
                'start_date' => 'required|date',
            ]);

            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            // Weekly schedule is same schedules (recurring), but you asked API exists,
            // so we return all schedules and include weekStart for reference.
            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->orderByRaw("FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
                ->orderBy('start_time')
                ->get()
                ->map(fn($item) => $this->mapScheduleItem($item));

            return response()->json([
                'data' => $schedule,
                'week_start' => $request->start_date,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@getWeekSchedule error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load week schedule'], 500);
        }
    }

    // ✅ NEW: /student/schedule/upcoming
    public function getUpcoming(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $todayName = now()->format('l');

            // We return the next few classes for today sorted by time (simple + useful)
            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->where('day_of_week', $todayName)
                ->orderBy('start_time')
                ->get()
                ->filter(function ($item) {
                    // Only after now time
                    $now = now()->format('H:i');
                    $start = substr((string)$item->start_time, 0, 5);
                    return $start >= $now;
                })
                ->values()
                ->take(5)
                ->map(fn($item) => $this->mapScheduleItem($item));

            return response()->json(['data' => $schedule], 200);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@getUpcoming error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load upcoming classes'], 500);
        }
    }

    // ✅ NEW: /student/schedule/download (return JSON for now; PDF later if you want)
    public function downloadSchedule(Request $request)
    {
        try {
            // easiest: return schedule JSON as downloadable file (no extra packages)
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->orderByRaw("FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
                ->orderBy('start_time')
                ->get()
                ->map(fn($item) => $this->mapScheduleItem($item));

            $json = json_encode(['data' => $schedule], JSON_PRETTY_PRINT);

            return response($json, 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="my-schedule.json"',
            ]);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@downloadSchedule error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to download schedule'], 500);
        }
    }
}
