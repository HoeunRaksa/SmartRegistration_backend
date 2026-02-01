<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Student;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\ClassSchedule;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Grade;
use App\Models\AttendanceRecord;
use App\Models\Message;
use Carbon\Carbon;

class StudentDashboardController extends Controller
{
    /**
     * GET /api/student/dashboard
     * Get complete dashboard data in one request
     */
    public function getDashboard(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $student = Student::where('user_id', $user->id)
                ->with(['user', 'department'])
                ->first();

            if (!$student) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student profile not found',
                ], 404);
            }

            // Get all data
            $enrolledCourses = $this->getEnrolledCoursesData($student);
            $todaySchedule = $this->getTodayScheduleData($student);
            $grades = $this->getGradesData($student);
            $gpa = $this->getGPAData($student);
            $assignments = $this->getAssignmentsData($student);
            $attendanceStats = $this->getAttendanceStatsData($student);
            $conversations = $this->getConversationsData($user);

            // ✅ Safe profile picture URL
            $profilePictureUrl = null;
            if ($student->user && $student->user->profile_picture_path) {
                $profilePictureUrl = url($student->user->profile_picture_path);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'student' => [
                        'name' => $user->name ?? 'Student',
                        'email' => $user->email ?? '',
                        'student_code' => $student->student_code ?? '',
                        'major' => $student->department?->name ?? '',
                        'year' => $student->generation ?? '',
                        'profile_picture_url' => $profilePictureUrl,
                    ],
                    'stats' => [
                        'gpa' => $gpa,
                        'enrolled_courses' => count($enrolledCourses),
                        'attendance' => $attendanceStats['percentage'] ?? 0,
                        'pending_assignments' => count(array_filter($assignments, function ($a) {
                            return empty($a['submissions']);
                        })),
                    ],
                    'enrolled_courses' => $enrolledCourses,
                    'today_schedule' => $todaySchedule,
                    'grades' => $grades,
                    'assignments' => $assignments,
                    'attendance' => $attendanceStats,
                    'conversations' => $conversations,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentDashboardController@getDashboard error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    private function getEnrolledCoursesData(Student $student): array
    {
        try {
            $enrollments = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->with(['course.majorSubject.subject', 'course.teacher.user', 'course.classGroup'])
                ->get();

            return $enrollments->map(function ($enrollment) {
                $course = $enrollment->course;
                if (!$course) return null;

                $subject = $course->majorSubject?->subject;
                $teacher = $course->teacher;

                return [
                    'id' => $course->id,
                    'course_code' => $subject?->subject_code ?? 'CODE-' . $course->id,
                    'course_name' => $subject?->subject_name ?? 'Untitled Course',
                    'credits' => $subject?->credits ?? 0,
                    'instructor_name' => $teacher?->user?->name ?? 'Unknown Instructor',
                    'semester' => $course->semester,
                    'academic_year' => $course->academic_year,
                ];
            })->filter()->values()->toArray();
        } catch (\Throwable $e) {
            Log::error('Error in getEnrolledCoursesData: ' . $e->getMessage());
            return [];
        }
    }

    private function getTodayScheduleData(Student $student): array
    {
        try {
            $today = Carbon::now()->format('l');
            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            if ($enrolledCourseIds->isEmpty()) {
                return [];
            }

            $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
                ->where('day_of_week', $today)
                ->with(['course.majorSubject.subject', 'course.teacher.user', 'roomRef'])
                ->orderBy('start_time', 'ASC')
                ->get();

            return $schedules->map(function ($schedule) {
                $course = $schedule->course;
                if (!$course) return null;

                $subject = $course->majorSubject?->subject;
                $teacher = $course->teacher;
                $room = $schedule->roomRef;

                return [
                    'id' => $schedule->id,
                    'course_code' => $subject?->subject_code ?? 'CODE-' . $course->id,
                    'course_name' => $subject?->subject_name ?? 'Untitled Course',
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'room' => $room ? $room->room_number : ($schedule->room ?? 'TBA'),
                    'instructor_name' => $teacher?->user?->name ?? 'Unknown Instructor',
                ];
            })->filter()->values()->toArray();
        } catch (\Throwable $e) {
            Log::error('Error in getTodayScheduleData: ' . $e->getMessage());
            return [];
        }
    }

    private function getGradesData(Student $student): array
    {
        try {
            $grades = Grade::where('student_id', $student->id)
                ->with(['course.majorSubject.subject'])
                ->orderBy('created_at', 'DESC')
                ->limit(10)
                ->get();

            return $grades->map(function ($grade) {
                $subject = $grade->course?->majorSubject?->subject;
                $date = $grade->created_at instanceof Carbon ? $grade->created_at->toISOString() : $grade->created_at;

                return [
                    'id' => $grade->id,
                    'course_code' => $subject?->subject_code ?? 'CODE-' . ($grade->course_id ?? '?'),
                    'course_name' => $subject?->subject_name ?? 'Untitled Course',
                    'assignment_name' => $grade->assignment_name ?? 'Assignment',
                    'score' => (float) ($grade->score ?? 0),
                    'total_points' => (float) ($grade->total_points ?? 100),
                    'created_at' => $date,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('Error in getGradesData: ' . $e->getMessage());
            return [];
        }
    }

    private function getGPAData(Student $student): float
    {
        try {
            $avg = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->avg('grade_point');

            return round((float) ($avg ?? 0), 2);
        } catch (\Throwable $e) {
            Log::error('Error in getGPAData: ' . $e->getMessage());
            return 0.0;
        }
    }

    private function getAssignmentsData(Student $student): array
    {
        try {
            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            if ($enrolledCourseIds->isEmpty()) {
                return [];
            }

            // Get pending and recent assignments
            $assignments = Assignment::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'submissions' => function ($query) use ($student) {
                        $query->where('student_id', $student->id);
                    }
                ])
                ->orderBy('due_date', 'ASC')
                ->take(20) // Limit to relevant ones
                ->get();

            return $assignments->map(function ($assignment) {
                $subject = $assignment->course?->majorSubject?->subject;

                // Format due date safely
                $dueDate = $assignment->due_date;
                if ($dueDate instanceof Carbon) {
                    $dueDateFormatted = $dueDate->format('Y-m-d');
                } else {
                    $dueDateFormatted = (string) $dueDate;
                }

                return [
                    'id' => $assignment->id,
                    'course_code' => $subject?->subject_code ?? 'CODE-' . ($assignment->course_id ?? '?'),
                    'title' => $assignment->title,
                    'due_date' => $dueDateFormatted,
                    'due_time' => $assignment->due_time,
                    'points' => (float) ($assignment->points ?? 0),
                    'submissions' => $assignment->submissions->map(function ($s) {
                        return [
                            'id' => $s->id,
                            'submitted_at' => $s->submitted_at instanceof Carbon ? $s->submitted_at->toISOString() : $s->submitted_at,
                            'score' => (float) ($s->score ?? 0),
                        ];
                    })->toArray(),
                ];
            })->toArray();
        } catch (\Throwable $e) {
            Log::error('Error in getAssignmentsData: ' . $e->getMessage());
            return [];
        }
    }

    private function getAttendanceStatsData(Student $student): array
    {
        try {
            $stats = AttendanceRecord::where('student_id', $student->id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
                ")
                ->first();

            $total = (int) ($stats->total ?? 0);
            $present = (int) ($stats->present ?? 0);
            $absent = (int) ($stats->absent ?? 0);
            $percentage = $total > 0 ? ($present / $total) * 100 : 0;

            return [
                'total' => $total,
                'present' => $present,
                'absent' => $absent,
                'percentage' => round($percentage, 2),
            ];
        } catch (\Throwable $e) {
            Log::error('Error in getAttendanceStatsData: ' . $e->getMessage());
            return [
                'total' => 0,
                'present' => 0,
                'absent' => 0,
                'percentage' => 0,
            ];
        }
    }

    private function getConversationsData($user): array
    {
        try {
            // Optimized query: Get all messages involving user, sort by latest
            $messages = Message::where('s_id', $user->id)
                ->orWhere('r_id', $user->id)
                ->with(['sender', 'receiver'])
                ->orderBy('created_at', 'desc')
                ->limit(50) // Fetch reasonable amount of recent history
                ->get();

            // Group by other participant to get unique conversations
            $conversations = $messages->map(function ($msg) use ($user) {
                $isSender = $msg->s_id == $user->id;
                $otherId = $isSender ? $msg->r_id : $msg->s_id;
                $otherUser = $isSender ? $msg->receiver : $msg->sender;

                if (!$otherUser) return null;

                return [
                    'id' => $otherId,
                    'participant_name' => $otherUser->name ?? 'Unknown',
                    'last_message' => $msg->content,
                    'last_message_time' => $msg->created_at instanceof Carbon ? $msg->created_at->toISOString() : $msg->created_at,
                    'timestamp' => $msg->created_at instanceof Carbon ? $msg->created_at->timestamp : 0,
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->take(5) // Just top 5 for dashboard
            ->toArray();

            return $conversations;
        } catch (\Throwable $e) {
            Log::error('Error in getConversationsData: ' . $e->getMessage());
            return [];
        }
    }
}