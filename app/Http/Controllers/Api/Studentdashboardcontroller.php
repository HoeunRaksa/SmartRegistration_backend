<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
use Illuminate\Support\Facades\Log;

class StudentDashboardController extends Controller
{
    /**
     * Get enrolled courses for the authenticated student
     * GET /api/student/courses/enrolled
     */
    public function getEnrolledCourses(Request $request)
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

            $enrollments = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'course.classGroup'
                ])
                ->get();

            $courses = $enrollments->map(function ($enrollment) {
                $course = $enrollment->course;
                $subject = $course->majorSubject?->subject;
                $teacher = $course->teacher;

                return [
                    'id' => $course->id,
                    'course_code' => $subject?->subject_code ?? 'N/A',
                    'code' => $subject?->subject_code ?? 'N/A',
                    'course_name' => $subject?->subject_name ?? 'N/A',
                    'title' => $subject?->subject_name ?? 'N/A',
                    'credits' => $subject?->credits ?? 0,
                    'instructor_name' => $teacher?->user?->name ?? 'N/A',
                    'instructor' => $teacher?->user?->name ?? 'N/A',
                    'semester' => $course->semester,
                    'academic_year' => $course->academic_year,
                    'class_group' => $course->classGroup?->class_name ?? 'N/A',
                    'status' => $enrollment->status,
                    'progress' => $enrollment->progress ?? 0,
                ];
            });

            return response()->json([
                'data' => $courses
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching enrolled courses: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch enrolled courses',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get today's schedule for the authenticated student
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

            // Get today's day name (e.g., "Monday")
            $today = Carbon::now()->format('l');

            // Get enrolled course IDs
            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            // Get today's schedules
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

            $todayClasses = $schedules->map(function ($schedule) {
                $course = $schedule->course;
                $subject = $course->majorSubject?->subject;
                $teacher = $course->teacher;
                $room = $schedule->roomRef;

                return [
                    'id' => $schedule->id,
                    'course' => [
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                        'title' => $subject?->subject_name ?? 'N/A',
                        'instructor_name' => $teacher?->user?->name ?? 'N/A',
                        'instructor' => $teacher?->user?->name ?? 'N/A',
                    ],
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'room' => $room ? $room->room_number . ' (' . $room->building?->building_name . ')' : ($schedule->room ?? 'N/A'),
                    'day_of_week' => $schedule->day_of_week,
                    'session_type' => $schedule->session_type,
                ];
            });

            return response()->json([
                'data' => $todayClasses
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching today schedule: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch schedule',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get all grades for the authenticated student
     * GET /api/student/grades
     */
    public function getGrades(Request $request)
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

            $grades = Grade::where('student_id', $student->id)
                ->with([
                    'course.majorSubject.subject',
                    'course.classGroup'
                ])
                ->orderBy('created_at', 'DESC')
                ->get();

            $formattedGrades = $grades->map(function ($grade) {
                $course = $grade->course;
                $subject = $course?->majorSubject?->subject;

                return [
                    'id' => $grade->id,
                    'course' => [
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                        'title' => $subject?->subject_name ?? 'N/A',
                    ],
                    'assignment_name' => $grade->assignment_name,
                    'score' => (float) $grade->score,
                    'total_points' => (float) $grade->total_points,
                    'letter_grade' => $grade->letter_grade,
                    'grade_point' => (float) $grade->grade_point,
                    'feedback' => $grade->feedback,
                    'created_at' => $grade->created_at->toISOString(),
                    'updated_at' => $grade->updated_at->toISOString(),
                ];
            });

            return response()->json([
                'data' => $formattedGrades
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching grades: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch grades',
                'data' => []
            ], 500);
        }
    }

    /**
     * Calculate and get student's GPA
     * GET /api/student/grades/gpa
     */
    public function getGPA(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => ['gpa' => 0]
                ], 404);
            }

            // Calculate GPA based on grade_point column
            $gpaData = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->selectRaw('AVG(grade_point) as average_gpa, COUNT(*) as total_grades')
                ->first();

            $gpa = $gpaData->average_gpa ?? 0;
            $totalGrades = $gpaData->total_grades ?? 0;

            // Alternative: Calculate GPA from percentage if grade_point is not set
            if ($totalGrades === 0 || !$gpa) {
                $grades = Grade::where('student_id', $student->id)
                    ->where('total_points', '>', 0)
                    ->get();

                if ($grades->count() > 0) {
                    $totalPoints = 0;
                    $count = 0;

                    foreach ($grades as $grade) {
                        $percentage = ($grade->score / $grade->total_points) * 100;
                        $gradePoint = $this->convertPercentageToGPA($percentage);
                        $totalPoints += $gradePoint;
                        $count++;
                    }

                    $gpa = $count > 0 ? $totalPoints / $count : 0;
                }
            }

            return response()->json([
                'data' => [
                    'gpa' => round((float) $gpa, 2),
                    'total_grades' => $totalGrades,
                ]
            ]);

        } catch (\Exception $e) {
           Log::error('Error calculating GPA: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to calculate GPA',
                'data' => ['gpa' => 0]
            ], 500);
        }
    }

    /**
     * Get all assignments with submission status
     * GET /api/student/assignments
     */
    public function getAssignments(Request $request)
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

            // Get enrolled course IDs
            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            // Get assignments for enrolled courses
            $assignments = Assignment::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'submissions' => function ($query) use ($student) {
                        $query->where('student_id', $student->id);
                    }
                ])
                ->orderBy('due_date', 'ASC')
                ->get();

            $formattedAssignments = $assignments->map(function ($assignment) {
                $course = $assignment->course;
                $subject = $course?->majorSubject?->subject;

                return [
                    'id' => $assignment->id,
                    'course' => [
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                        'title' => $subject?->subject_name ?? 'N/A',
                    ],
                    'title' => $assignment->title,
                    'description' => $assignment->description,
                    'due_date' => $assignment->due_date ? $assignment->due_date->format('Y-m-d') : null,
                    'due_time' => $assignment->due_time,
                    'points' => (float) $assignment->points,
                    'attachment_path' => $assignment->attachment_path,
                    'submissions' => $assignment->submissions->map(function ($submission) {
                        return [
                            'id' => $submission->id,
                            'submitted_at' => $submission->submitted_at?->toISOString(),
                            'score' => (float) $submission->score,
                            'status' => $submission->status,
                            'feedback' => $submission->feedback,
                        ];
                    }),
                ];
            });

            return response()->json([
                'data' => $formattedAssignments
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching assignments: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch assignments',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get attendance statistics for the authenticated student
     * GET /api/student/attendance/stats
     */
    public function getAttendanceStats(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => ['total' => 0, 'present' => 0]
                ], 404);
            }

            // Get attendance statistics
            $stats = AttendanceRecord::where('student_id', $student->id)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as late
                ')
                ->first();

            $total = $stats->total ?? 0;
            $present = $stats->present ?? 0;
            $absent = $stats->absent ?? 0;
            $late = $stats->late ?? 0;

            $percentage = $total > 0 ? ($present / $total) * 100 : 0;

            return response()->json([
                'data' => [
                    'total' => (int) $total,
                    'present' => (int) $present,
                    'absent' => (int) $absent,
                    'late' => (int) $late,
                    'percentage' => round($percentage, 2),
                ]
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching attendance stats: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch attendance statistics',
                'data' => ['total' => 0, 'present' => 0]
            ], 500);
        }
    }

    /**
     * Get message conversations for the authenticated student
     * GET /api/student/messages/conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $user = $request->user();

            // Get unique conversation partners (users the student has messaged with)
            $sentTo = Message::where('s_id', $user->id)
                ->select('r_id as user_id')
                ->distinct();

            $receivedFrom = Message::where('r_id', $user->id)
                ->select('s_id as user_id')
                ->distinct();

            // Combine and get unique user IDs
            $conversationUserIds = $sentTo->union($receivedFrom)->pluck('user_id');

            // Get the latest message for each conversation
            $conversations = [];
            foreach ($conversationUserIds as $userId) {
                $latestMessage = Message::where(function ($query) use ($user, $userId) {
                    $query->where('s_id', $user->id)->where('r_id', $userId);
                })->orWhere(function ($query) use ($user, $userId) {
                    $query->where('s_id', $userId)->where('r_id', $user->id);
                })
                ->with(['sender', 'receiver'])
                ->orderBy('created_at', 'DESC')
                ->first();

                if ($latestMessage) {
                    // Count unread messages from this user
                    $unreadCount = Message::where('s_id', $userId)
                        ->where('r_id', $user->id)
                        ->where('is_read', false)
                        ->count();

                    // Get the other participant
                    $participant = $latestMessage->s_id == $user->id
                        ? $latestMessage->receiver
                        : $latestMessage->sender;

                    $conversations[] = [
                        'id' => $userId,
                        'participant_name' => $participant?->name ?? 'Unknown',
                        'participant_email' => $participant?->email ?? '',
                        'last_message' => $latestMessage->content,
                        'last_message_time' => $latestMessage->created_at->toISOString(),
                        'unread_count' => $unreadCount,
                        'is_sender' => $latestMessage->s_id == $user->id,
                    ];
                }
            }

            // Sort by latest message time
            usort($conversations, function ($a, $b) {
                return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
            });

            return response()->json([
                'data' => $conversations
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching conversations: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch conversations',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get complete dashboard data (all in one request)
     * GET /api/student/dashboard
     */
    public function getDashboard(Request $request)
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

            // Get all data in parallel (more efficient)
            $enrolledCourses = $this->getEnrolledCoursesData($student);
            $todaySchedule = $this->getTodayScheduleData($student);
            $grades = $this->getGradesData($student);
            $gpa = $this->getGPAData($student);
            $assignments = $this->getAssignmentsData($student);
            $attendanceStats = $this->getAttendanceStatsData($student);
            $conversations = $this->getConversationsData($user);

            return response()->json([
                'data' => [
                    'student' => [
                        'name' => $user->name,
                        'email' => $user->email,
                        'student_code' => $student->student_code,
                        'major' => $student->department?->department_name ?? 'N/A',
                        'year' => $student->generation ?? 'N/A',
                        'profile_picture_url' => $student->profile_picture_url,
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
            ]);

        } catch (\Exception $e) {
           Log::error('Error fetching dashboard: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch dashboard data',
            ], 500);
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Convert percentage to GPA (4.0 scale)
     */
    private function convertPercentageToGPA(float $percentage): float
    {
        if ($percentage >= 90) return 4.0;
        if ($percentage >= 85) return 3.7;
        if ($percentage >= 80) return 3.3;
        if ($percentage >= 75) return 3.0;
        if ($percentage >= 70) return 2.7;
        if ($percentage >= 65) return 2.3;
        if ($percentage >= 60) return 2.0;
        if ($percentage >= 55) return 1.7;
        if ($percentage >= 50) return 1.3;
        return 0.0;
    }

    /**
     * Get enrolled courses data (helper for combined dashboard)
     */
    private function getEnrolledCoursesData(Student $student): array
    {
        $enrollments = CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->with(['course.majorSubject.subject', 'course.teacher.user', 'course.classGroup'])
            ->get();

        return $enrollments->map(function ($enrollment) {
            $course = $enrollment->course;
            $subject = $course->majorSubject?->subject;
            $teacher = $course->teacher;

            return [
                'id' => $course->id,
                'course_code' => $subject?->subject_code ?? 'N/A',
                'course_name' => $subject?->subject_name ?? 'N/A',
                'credits' => $subject?->credits ?? 0,
                'instructor_name' => $teacher?->user?->name ?? 'N/A',
                'semester' => $course->semester,
                'academic_year' => $course->academic_year,
            ];
        })->toArray();
    }

    /**
     * Get today's schedule data (helper for combined dashboard)
     */
    private function getTodayScheduleData(Student $student): array
    {
        $today = Carbon::now()->format('l');
        $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->pluck('course_id');

        $schedules = ClassSchedule::whereIn('course_id', $enrolledCourseIds)
            ->where('day_of_week', $today)
            ->with(['course.majorSubject.subject', 'course.teacher.user', 'roomRef'])
            ->orderBy('start_time', 'ASC')
            ->get();

        return $schedules->map(function ($schedule) {
            $course = $schedule->course;
            $subject = $course->majorSubject?->subject;
            $teacher = $course->teacher;
            $room = $schedule->roomRef;

            return [
                'id' => $schedule->id,
                'course_code' => $subject?->subject_code ?? 'N/A',
                'course_name' => $subject?->subject_name ?? 'N/A',
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'room' => $room ? $room->room_number : ($schedule->room ?? 'N/A'),
                'instructor_name' => $teacher?->user?->name ?? 'N/A',
            ];
        })->toArray();
    }

    /**
     * Get grades data (helper for combined dashboard)
     */
    private function getGradesData(Student $student): array
    {
        $grades = Grade::where('student_id', $student->id)
            ->with(['course.majorSubject.subject'])
            ->orderBy('created_at', 'DESC')
            ->limit(10)
            ->get();

        return $grades->map(function ($grade) {
            $subject = $grade->course?->majorSubject?->subject;

            return [
                'id' => $grade->id,
                'course_code' => $subject?->subject_code ?? 'N/A',
                'course_name' => $subject?->subject_name ?? 'N/A',
                'assignment_name' => $grade->assignment_name,
                'score' => (float) $grade->score,
                'total_points' => (float) $grade->total_points,
                'created_at' => $grade->created_at->toISOString(),
            ];
        })->toArray();
    }

    /**
     * Get GPA data (helper for combined dashboard)
     */
    private function getGPAData(Student $student): float
    {
        $gpaData = Grade::where('student_id', $student->id)
            ->whereNotNull('grade_point')
            ->avg('grade_point');

        return round((float) ($gpaData ?? 0), 2);
    }

    /**
     * Get assignments data (helper for combined dashboard)
     */
    private function getAssignmentsData(Student $student): array
    {
        $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
            ->where('status', 'enrolled')
            ->pluck('course_id');

        $assignments = Assignment::whereIn('course_id', $enrolledCourseIds)
            ->with([
                'course.majorSubject.subject',
                'submissions' => function ($query) use ($student) {
                    $query->where('student_id', $student->id);
                }
            ])
            ->orderBy('due_date', 'ASC')
            ->get();

        return $assignments->map(function ($assignment) {
            $subject = $assignment->course?->majorSubject?->subject;

            return [
                'id' => $assignment->id,
                'course_code' => $subject?->subject_code ?? 'N/A',
                'title' => $assignment->title,
                'due_date' => $assignment->due_date ? $assignment->due_date->format('Y-m-d') : null,
                'due_time' => $assignment->due_time,
                'points' => (float) $assignment->points,
                'submissions' => $assignment->submissions->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'submitted_at' => $s->submitted_at?->toISOString(),
                        'score' => (float) $s->score,
                    ];
                })->toArray(),
            ];
        })->toArray();
    }

    /**
     * Get attendance stats data (helper for combined dashboard)
     */
    private function getAttendanceStatsData(Student $student): array
    {
        $stats = AttendanceRecord::where('student_id', $student->id)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent
            ')
            ->first();

        $total = $stats->total ?? 0;
        $present = $stats->present ?? 0;
        $percentage = $total > 0 ? ($present / $total) * 100 : 0;

        return [
            'total' => (int) $total,
            'present' => (int) $present,
            'absent' => (int) ($stats->absent ?? 0),
            'percentage' => round($percentage, 2),
        ];
    }

    /**
     * Get conversations data (helper for combined dashboard)
     */
    private function getConversationsData($user): array
    {
        $sentTo = Message::where('s_id', $user->id)->select('r_id as user_id')->distinct();
        $receivedFrom = Message::where('r_id', $user->id)->select('s_id as user_id')->distinct();
        $conversationUserIds = $sentTo->union($receivedFrom)->pluck('user_id');

        $conversations = [];
        foreach ($conversationUserIds as $userId) {
            $latestMessage = Message::where(function ($query) use ($user, $userId) {
                $query->where('s_id', $user->id)->where('r_id', $userId);
            })->orWhere(function ($query) use ($user, $userId) {
                $query->where('s_id', $userId)->where('r_id', $user->id);
            })
            ->with(['sender', 'receiver'])
            ->orderBy('created_at', 'DESC')
            ->first();

            if ($latestMessage) {
                $participant = $latestMessage->s_id == $user->id
                    ? $latestMessage->receiver
                    : $latestMessage->sender;

                $conversations[] = [
                    'id' => $userId,
                    'participant_name' => $participant?->name ?? 'Unknown',
                    'last_message' => $latestMessage->content,
                    'last_message_time' => $latestMessage->created_at->toISOString(),
                ];
            }
        }

        return $conversations;
    }
}