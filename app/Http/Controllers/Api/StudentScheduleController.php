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

        $colors = [
            'from-blue-500 to-cyan-500',
            'from-purple-500 to-pink-500',
            'from-green-500 to-emerald-500',
            'from-orange-500 to-red-500',
            'from-indigo-500 to-purple-500',
            'from-teal-500 to-green-500',
        ];

        $cid = (int)($item->course_id ?? 0);
        $color = $colors[$cid % count($colors)];

        $subject = $course?->majorSubject?->subject;

        $courseName = $subject?->subject_name ?? $subject?->name ?? null;
        $courseCode = $subject?->code ?? null;

        $teacherName =
            $course?->teacher?->name ??
            $course?->teacher?->full_name ??
            null;

        return [
            'id' => $item->id,
            'course_id' => $item->course_id,

            'course_code' => $courseCode,
            'course_name' => $courseName,
            'instructor'  => $teacherName,

            'day' => $item->day_of_week,
            'day_of_week' => $item->day_of_week,

            'start_time' => substr((string)$item->start_time, 0, 5),
            'end_time'   => substr((string)$item->end_time, 0, 5),
            'room'       => $item->room,
            'session_type' => $item->session_type,

            'color' => $color,
        ];
    }

    private function enrolledCourseIds(Student $student)
    {
        return CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->distinct()
            ->pluck('course_id');
    }

    public function getSchedule(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = $this->enrolledCourseIds($student);

            $schedule = ClassSchedule::with(['course.majorSubject.subject', 'course.teacher'])
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
            $today = now()->format('l');

            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = $this->enrolledCourseIds($student);

            $schedule = ClassSchedule::with(['course.majorSubject.subject', 'course.teacher'])
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

    public function getWeekSchedule(Request $request)
    {
        try {
            $request->validate([
                'start_date' => 'required|date',
            ]);

            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = $this->enrolledCourseIds($student);

            $schedule = ClassSchedule::with(['course.majorSubject.subject', 'course.teacher'])
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

    public function getUpcoming(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = $this->enrolledCourseIds($student);

            $todayName = now()->format('l');

            $schedule = ClassSchedule::with(['course.majorSubject.subject', 'course.teacher'])
                ->whereIn('course_id', $courseIds)
                ->where('day_of_week', $todayName)
                ->orderBy('start_time')
                ->get()
                ->filter(function ($item) {
                    $now = now();
                    $startAt = now()->setTimeFromTimeString(substr((string)$item->start_time, 0, 5));
                    return $startAt->greaterThanOrEqualTo($now);
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

    public function downloadSchedule(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = $this->enrolledCourseIds($student);

            $schedule = ClassSchedule::with(['course.majorSubject.subject', 'course.teacher'])
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
