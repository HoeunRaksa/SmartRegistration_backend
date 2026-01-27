<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\CourseEnrollment;
use App\Models\ClassSchedule;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
class StudentScheduleController extends Controller
{
    /**
     * Get full schedule
     * GET /api/student/schedule
     */
    public function getSchedule(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => []
                ], 404);
            }

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup',
                    'roomRef'
                ])
                ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")
                ->orderBy('start_time', 'ASC')
                ->get();

            $formattedSchedule = $schedules->map(function ($schedule) {
                return $this->formatScheduleItem($schedule);
            });

            return response()->json([
                'data' => $formattedSchedule
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching schedule: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch schedule',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get today's schedule
     * GET /api/student/schedule/today
     */
    public function getTodaySchedule(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => []
                ], 404);
            }

            $today = Carbon::now()->format('l'); // Monday, Tuesday, etc.

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
                ->where('day_of_week', $today)
                ->with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup',
                    'roomRef'
                ])
                ->orderBy('start_time', 'ASC')
                ->get();

            $formattedSchedule = $schedules->map(function ($schedule) {
                return $this->formatScheduleItem($schedule);
            });

            return response()->json([
                'data' => $formattedSchedule
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching today schedule: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch today\'s schedule',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get week schedule (current week)
     * GET /api/student/schedule/week
     */
    public function getWeekSchedule(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => []
                ], 404);
            }

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup',
                    'roomRef'
                ])
                ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")
                ->orderBy('start_time', 'ASC')
                ->get();

            // Group by day of week
            $weekSchedule = [
                'Monday' => [],
                'Tuesday' => [],
                'Wednesday' => [],
                'Thursday' => [],
                'Friday' => [],
                'Saturday' => [],
                'Sunday' => [],
            ];

            foreach ($schedules as $schedule) {
                $dayOfWeek = $schedule->day_of_week;
                if (isset($weekSchedule[$dayOfWeek])) {
                    $weekSchedule[$dayOfWeek][] = $this->formatScheduleItem($schedule);
                }
            }

            return response()->json([
                'data' => $weekSchedule
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching week schedule: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch week schedule',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get upcoming classes (next 7 days)
     * GET /api/student/schedule/upcoming
     */
    public function getUpcoming(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => []
                ], 404);
            }

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            // Get schedules for enrolled courses
            $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup',
                    'roomRef'
                ])
                ->get();

            // Filter upcoming classes for next 7 days
            $upcomingClasses = [];
            $now = Carbon::now();
            $endDate = $now->copy()->addDays(7);

            for ($date = $now->copy(); $date->lte($endDate); $date->addDay()) {
                $dayName = $date->format('l');
                
                foreach ($schedules as $schedule) {
                    if ($schedule->day_of_week === $dayName) {
                        $classDateTime = $date->copy()->setTimeFromTimeString($schedule->start_time);
                        
                        // Only include future classes
                        if ($classDateTime->gt(Carbon::now())) {
                            $upcomingClasses[] = array_merge(
                                $this->formatScheduleItem($schedule),
                                [
                                    'date' => $date->format('Y-m-d'),
                                    'datetime' => $classDateTime->toISOString(),
                                ]
                            );
                        }
                    }
                }
            }

            // Sort by datetime
            usort($upcomingClasses, function ($a, $b) {
                return strtotime($a['datetime']) - strtotime($b['datetime']);
            });

            return response()->json([
                'data' => $upcomingClasses
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching upcoming classes: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch upcoming classes',
                'data' => []
            ], 500);
        }
    }

    /**
     * Download schedule as PDF
     * GET /api/student/schedule/download
     */
    public function downloadSchedule(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                ], 404);
            }

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup',
                    'roomRef'
                ])
                ->orderByRaw("FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')")
                ->orderBy('start_time', 'ASC')
                ->get();

            // Group by day
            $weekSchedule = [
                'Monday' => [],
                'Tuesday' => [],
                'Wednesday' => [],
                'Thursday' => [],
                'Friday' => [],
                'Saturday' => [],
                'Sunday' => [],
            ];

            foreach ($schedules as $schedule) {
                $dayOfWeek = $schedule->day_of_week;
                if (isset($weekSchedule[$dayOfWeek])) {
                    $weekSchedule[$dayOfWeek][] = $this->formatScheduleItem($schedule);
                }
            }

            $data = [
                'student' => $student,
                'user' => $user,
                'schedule' => $weekSchedule,
                'generated_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ];

            $pdf = Pdf::loadView('pdf.student-schedule', $data);
            
            return $pdf->download('schedule-' . $student->student_code . '.pdf');

        } catch (\Exception $e) {
            Log::error('Error downloading schedule: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to download schedule. Make sure barryvdh/laravel-dompdf is installed.',
            ], 500);
        }
    }

    /**
     * Helper: Format schedule item
     */
    private function formatScheduleItem(ClassSchedule $schedule): array
    {
        $course = $schedule->course;
        $subject = $course->majorSubject?->subject;
        $teacher = $course->teacher;
        $room = $schedule->roomRef;

        return [
            'id' => $schedule->id,
            'course' => [
                'id' => $course->id,
                'course_code' => $subject?->subject_code ?? '',
                'code' => $subject?->subject_code ?? '',
                'course_name' => $subject?->subject_name ?? '',
                'title' => $subject?->subject_name ?? '',
                'instructor_name' => $teacher?->user?->name ?? 'None',
                'instructor' => $teacher?->user?->name ?? 'None',
            ],
            'day_of_week' => $schedule->day_of_week,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'room' => $room 
                ? $room->room_number . ' (' . ($room->building?->building_name ?? '') . ')' 
                : ($schedule->room ?? ''),
            'session_type' => $schedule->session_type,
        ];
    }
}