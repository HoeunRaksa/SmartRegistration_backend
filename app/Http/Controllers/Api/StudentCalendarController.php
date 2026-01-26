<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\CourseEnrollment;
use App\Models\ClassSchedule;
use App\Models\ClassSession;
use App\Models\Assignment;
use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StudentCalendarController extends Controller
{
    /**
     * Get calendar events for the authenticated student
     * GET /api/calendar or /api/student/calendar
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $student = Student::where('user_id', $user->id)->first();
            
            if (!$student) {
                // Return empty for non-student users
                return response()->json(['data' => []], 200);
            }

            $month = $request->get('month', date('m'));
            $year = $request->get('year', date('Y'));

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            $events = $this->getCalendarEvents($student, $startDate, $endDate);

            return response()->json(['data' => $events], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load calendar'], 500);
        }
    }

    /**
     * Get calendar events for a specific date range
     * GET /api/student/calendar/range
     */
    public function getRange(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $startDate = Carbon::parse($request->get('start_date', now()->startOfMonth()));
            $endDate = Carbon::parse($request->get('end_date', now()->endOfMonth()));

            $events = $this->getCalendarEvents($student, $startDate, $endDate);

            return response()->json(['data' => $events], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@getRange error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load calendar range'], 500);
        }
    }

    /**
     * Get events for a specific date
     * GET /api/student/calendar/date/{date}
     */
    public function getByDate(Request $request, $date)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $targetDate = Carbon::parse($date);
            $events = $this->getCalendarEvents($student, $targetDate, $targetDate);

            return response()->json(['data' => $events], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@getByDate error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load events for date'], 500);
        }
    }

    /**
     * Get today's events
     * GET /api/student/calendar/today
     */
    public function getToday(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $today = Carbon::today();
            $events = $this->getCalendarEvents($student, $today, $today);

            return response()->json([
                'data' => [
                    'date' => $today->format('Y-m-d'),
                    'day_name' => $today->format('l'),
                    'events' => $events,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@getToday error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load today\'s events'], 500);
        }
    }

    /**
     * Get weekly calendar view
     * GET /api/student/calendar/week
     */
    public function getWeek(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $startOfWeek = Carbon::now()->startOfWeek();
            $endOfWeek = Carbon::now()->endOfWeek();

            $allEvents = $this->getCalendarEvents($student, $startOfWeek, $endOfWeek);

            // Group events by date
            $weekData = [];
            for ($date = $startOfWeek->copy(); $date <= $endOfWeek; $date->addDay()) {
                $dateStr = $date->format('Y-m-d');
                $weekData[$dateStr] = [
                    'date' => $dateStr,
                    'day_name' => $date->format('l'),
                    'is_today' => $date->isToday(),
                    'events' => array_values(array_filter($allEvents, fn($e) => 
                        isset($e['date']) && $e['date'] === $dateStr
                    )),
                ];
            }

            return response()->json(['data' => array_values($weekData)], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@getWeek error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load weekly calendar'], 500);
        }
    }

    /**
     * Get upcoming events
     * GET /api/student/calendar/upcoming
     */
    public function getUpcoming(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $limit = $request->get('limit', 10);
            $days = $request->get('days', 14);

            $startDate = Carbon::today();
            $endDate = Carbon::today()->addDays($days);

            $events = $this->getCalendarEvents($student, $startDate, $endDate);

            // Sort by date/time and limit
            usort($events, function ($a, $b) {
                $dateA = ($a['date'] ?? '') . ' ' . ($a['start_time'] ?? '00:00');
                $dateB = ($b['date'] ?? '') . ' ' . ($b['start_time'] ?? '00:00');
                return strcmp($dateA, $dateB);
            });

            $events = array_slice($events, 0, $limit);

            return response()->json(['data' => $events], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@getUpcoming error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load upcoming events'], 500);
        }
    }

    /**
     * Get calendar events helper
     */
    private function getCalendarEvents(Student $student, Carbon $startDate, Carbon $endDate): array
    {
        $events = [];

        // Get enrolled course IDs
        $courseIds = CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->pluck('course_id');

        if ($courseIds->isEmpty()) {
            return $events;
        }

        // 1. Get scheduled classes (recurring weekly schedule)
        $schedules = ClassSchedule::whereIn('course_id', $courseIds)
            ->with(['course.majorSubject.subject', 'course.teacher.user', 'roomRef'])
            ->get();

        foreach ($schedules as $schedule) {
            for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
                if ($date->format('l') === $schedule->day_of_week) {
                    $subject = $schedule->course?->majorSubject?->subject;
                    $events[] = [
                        'id' => 'schedule_' . $schedule->id . '_' . $date->format('Y-m-d'),
                        'type' => 'class',
                        'title' => $subject?->subject_name ?? 'Class',
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                        'date' => $date->format('Y-m-d'),
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                        'room' => $schedule->roomRef?->room_number ?? $schedule->room ?? 'N/A',
                        'instructor' => $schedule->course?->teacher?->user?->name ?? 'N/A',
                        'color' => '#3B82F6', // Blue for classes
                    ];
                }
            }
        }

        // 2. Get class sessions (specific dated sessions)
        $sessions = ClassSession::whereIn('course_id', $courseIds)
            ->whereBetween('session_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->with(['course.majorSubject.subject', 'course.teacher.user'])
            ->get();

        foreach ($sessions as $session) {
            $subject = $session->course?->majorSubject?->subject;
            
            // Check if student has attendance for this session
            $attendance = AttendanceRecord::where('student_id', $student->id)
                ->where('class_session_id', $session->id)
                ->first();

            $events[] = [
                'id' => 'session_' . $session->id,
                'type' => 'session',
                'title' => $subject?->subject_name ?? 'Class Session',
                'course_code' => $subject?->subject_code ?? 'N/A',
                'course_name' => $subject?->subject_name ?? 'N/A',
                'date' => $session->session_date,
                'start_time' => $session->start_time,
                'end_time' => $session->end_time,
                'topic' => $session->topic ?? null,
                'instructor' => $session->course?->teacher?->user?->name ?? 'N/A',
                'attendance_status' => $attendance?->status ?? null,
                'color' => '#10B981', // Green for sessions
            ];
        }

        // 3. Get assignment due dates
        $assignments = Assignment::whereIn('course_id', $courseIds)
            ->whereBetween('due_date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->with(['course.majorSubject.subject', 'submissions' => function ($q) use ($student) {
                $q->where('student_id', $student->id);
            }])
            ->get();

        foreach ($assignments as $assignment) {
            $subject = $assignment->course?->majorSubject?->subject;
            $submission = $assignment->submissions->first();
            $dueDate = Carbon::parse($assignment->due_date);
            $isOverdue = $dueDate->isPast() && !$submission;

            $events[] = [
                'id' => 'assignment_' . $assignment->id,
                'type' => 'assignment',
                'title' => $assignment->title,
                'course_code' => $subject?->subject_code ?? 'N/A',
                'course_name' => $subject?->subject_name ?? 'N/A',
                'date' => $assignment->due_date,
                'due_time' => $assignment->due_time,
                'points' => $assignment->points,
                'is_submitted' => $submission !== null,
                'is_overdue' => $isOverdue,
                'submission_status' => $submission?->status ?? ($isOverdue ? 'overdue' : 'pending'),
                'color' => $isOverdue ? '#EF4444' : ($submission ? '#10B981' : '#F59E0B'), // Red/Green/Yellow
            ];
        }

        // Sort by date and time
        usort($events, function ($a, $b) {
            $dateCompare = strcmp($a['date'] ?? '', $b['date'] ?? '');
            if ($dateCompare !== 0) return $dateCompare;
            return strcmp($a['start_time'] ?? $a['due_time'] ?? '', $b['start_time'] ?? $b['due_time'] ?? '');
        });

        return $events;
    }

    /**
     * Get monthly calendar with summary
     * GET /api/student/calendar/month
     */
    public function getMonth(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $month = $request->get('month', date('m'));
            $year = $request->get('year', date('Y'));

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            $events = $this->getCalendarEvents($student, $startDate, $endDate);

            // Group events by date
            $calendar = [];
            for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
                $dateStr = $date->format('Y-m-d');
                $dayEvents = array_values(array_filter($events, fn($e) => 
                    isset($e['date']) && $e['date'] === $dateStr
                ));
                
                $calendar[] = [
                    'date' => $dateStr,
                    'day' => $date->day,
                    'day_name' => $date->format('D'),
                    'is_today' => $date->isToday(),
                    'is_weekend' => $date->isWeekend(),
                    'event_count' => count($dayEvents),
                    'has_classes' => count(array_filter($dayEvents, fn($e) => $e['type'] === 'class' || $e['type'] === 'session')) > 0,
                    'has_assignments' => count(array_filter($dayEvents, fn($e) => $e['type'] === 'assignment')) > 0,
                    'events' => $dayEvents,
                ];
            }

            return response()->json([
                'data' => [
                    'month' => (int) $month,
                    'year' => (int) $year,
                    'month_name' => $startDate->format('F'),
                    'days_in_month' => $startDate->daysInMonth,
                    'first_day_of_week' => $startDate->dayOfWeek,
                    'calendar' => $calendar,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentCalendarController@getMonth error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load monthly calendar'], 500);
        }
    }
}
