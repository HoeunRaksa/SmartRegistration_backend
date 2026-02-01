<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CourseEnrollment;
use App\Models\ClassSession;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StudentAttendanceController extends Controller
{
    /**
     * Get attendance records for enrolled courses
     * GET /api/student/attendance
     */
    public function getAttendance(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $records = AttendanceRecord::with([
                    'classSession.course.majorSubject.subject',
                    'classSession.course.teacher.user'
                ])
                ->where('student_id', $student->id)
                ->whereHas('classSession', function ($q) use ($courseIds) {
                    $q->whereIn('course_id', $courseIds);
                })
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($record) {
                    $session = $record->classSession;
                    $course = $session?->course;
                    $subject = $course?->majorSubject?->subject;

                    return [
                        'id' => $record->id,
                        'session_id' => $session?->id,
                        'course_id' => $course?->id,
                        'course_code' => $subject?->subject_code ?? 'CODE-' . $course?->id,
                        'course_name' => $subject?->subject_name ?? 'Untitled Course',
                        'instructor' => $course?->teacher?->user?->name ?? 'Unknown Instructor',
                        'session_date' => $session?->session_date,
                        'session_time' => $session?->start_time . ' - ' . $session?->end_time,
                        'status' => $record->status,
                        'status_label' => $this->getStatusLabel($record->status),
                        'remarks' => $record->remarks,
                        'marked_at' => $record->created_at->toISOString(),
                    ];
                });

            return response()->json(['data' => $records], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getAttendance error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance'], 500);
        }
    }

    /**
     * Get attendance by course
     * GET /api/student/attendance/course/{courseId}
     */
    public function getAttendanceByCourse(Request $request, $courseId)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            // Verify enrollment
            $isEnrolled = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->where('status', 'enrolled')
                ->exists();

            if (!$isEnrolled) {
                return response()->json(['message' => 'Not enrolled in this course'], 403);
            }

            $records = AttendanceRecord::with(['classSession'])
                ->where('student_id', $student->id)
                ->whereHas('classSession', function ($q) use ($courseId) {
                    $q->where('course_id', $courseId);
                })
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($record) {
                    $session = $record->classSession;
                    return [
                        'id' => $record->id,
                        'session_date' => $session?->session_date,
                        'session_time' => $session?->start_time . ' - ' . $session?->end_time,
                        'status' => $record->status,
                        'status_label' => $this->getStatusLabel($record->status),
                        'remarks' => $record->remarks,
                    ];
                });

            // Calculate course-specific stats
            $total = $records->count();
            $present = $records->where('status', 'present')->count();
            $absent = $records->where('status', 'absent')->count();
            $late = $records->where('status', 'late')->count();

            return response()->json([
                'data' => [
                    'records' => $records,
                    'stats' => [
                        'total' => $total,
                        'present' => $present,
                        'absent' => $absent,
                        'late' => $late,
                        'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 100,
                    ]
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getAttendanceByCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load course attendance'], 500);
        }
    }

    /**
     * Get attendance statistics
     * GET /api/student/attendance/stats
     */
    public function getStats(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $total = AttendanceRecord::where('student_id', $student->id)->count();
            $present = AttendanceRecord::where('student_id', $student->id)->where('status', 'present')->count();
            $absent = AttendanceRecord::where('student_id', $student->id)->where('status', 'absent')->count();
            $late = AttendanceRecord::where('student_id', $student->id)->where('status', 'late')->count();
            $excused = AttendanceRecord::where('student_id', $student->id)->where('status', 'excused')->count();

            // Calculate rate (present + late counts as attended)
            $attended = $present + $late;
            $attendanceRate = $total > 0 ? round(($attended / $total) * 100, 1) : 100;

            // Get recent trend (last 30 days)
            $recentRecords = AttendanceRecord::where('student_id', $student->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->get();
            
            $recentTotal = $recentRecords->count();
            $recentPresent = $recentRecords->where('status', 'present')->count();
            $recentRate = $recentTotal > 0 ? round(($recentPresent / $recentTotal) * 100, 1) : 100;

            // Get status breakdown
            $status = 'good';
            if ($attendanceRate < 75) $status = 'warning';
            if ($attendanceRate < 60) $status = 'critical';

            return response()->json([
                'data' => [
                    'total' => $total,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'excused' => $excused,
                    'attendance_rate' => $attendanceRate,
                    'recent_rate' => $recentRate,
                    'status' => $status,
                    'status_message' => $this->getStatusMessage($attendanceRate),
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getStats error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance stats'], 500);
        }
    }

    /**
     * Get attendance summary by course
     * GET /api/student/attendance/summary
     */
    public function getSummary(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $summary = [];

            foreach ($courseIds as $courseId) {
                $records = AttendanceRecord::where('student_id', $student->id)
                    ->whereHas('classSession', fn($q) => $q->where('course_id', $courseId))
                    ->get();

                if ($records->isEmpty()) continue;

                $classSession = ClassSession::where('course_id', $courseId)
                    ->with('course.majorSubject.subject')
                    ->first();

                $subject = $classSession?->course?->majorSubject?->subject;
                
                $total = $records->count();
                $present = $records->where('status', 'present')->count();
                $absent = $records->where('status', 'absent')->count();
                $late = $records->where('status', 'late')->count();

                $summary[] = [
                    'course_id' => $courseId,
                    'course_code' => $subject?->subject_code ?? 'CODE-' . $courseId,
                    'course_name' => $subject?->subject_name ?? 'Untitled Course',
                    'total_sessions' => $total,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'attendance_rate' => $total > 0 ? round((($present + $late) / $total) * 100, 1) : 100,
                    'status' => $this->getCourseAttendanceStatus($present + $late, $total),
                ];
            }

            return response()->json(['data' => $summary], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getSummary error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance summary'], 500);
        }
    }

    /**
     * Get calendar view of attendance
     * GET /api/student/attendance/calendar
     */
    public function getCalendar(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $month = $request->get('month', date('m'));
            $year = $request->get('year', date('Y'));

            $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $endDate = $startDate->copy()->endOfMonth();

            $records = AttendanceRecord::with(['classSession.course.majorSubject.subject'])
                ->where('student_id', $student->id)
                ->whereHas('classSession', function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('session_date', [$startDate, $endDate]);
                })
                ->get()
                ->groupBy(fn($r) => $r->classSession?->session_date)
                ->map(function ($dayRecords, $date) {
                    return [
                        'date' => $date,
                        'sessions' => $dayRecords->map(fn($r) => [
                            'course' => $r->classSession?->course?->majorSubject?->subject?->subject_name ?? 'Untitled Course',
                            'status' => $r->status,
                        ])->values(),
                    ];
                })->values();

            return response()->json(['data' => $records], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getCalendar error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance calendar'], 500);
        }
    }

    /**
     * Get status label
     */
    private function getStatusLabel(string $status): string
    {
        return match ($status) {
            'present' => 'Present',
            'absent' => 'Absent',
            'late' => 'Late',
            'excused' => 'Excused',
            default => ucfirst($status),
        };
    }

    /**
     * Get status message based on attendance rate
     */
    private function getStatusMessage(float $rate): string
    {
        if ($rate >= 90) return 'Excellent attendance!';
        if ($rate >= 80) return 'Good attendance';
        if ($rate >= 75) return 'Satisfactory attendance';
        if ($rate >= 60) return 'Attendance needs improvement';
        return 'Critical: Risk of failing due to low attendance';
    }

    /**
     * Get course attendance status
     */
    private function getCourseAttendanceStatus(int $present, int $total): string
    {
        if ($total === 0) return 'no_data';
        $rate = ($present / $total) * 100;
        if ($rate >= 80) return 'good';
        if ($rate >= 60) return 'warning';
        return 'critical';
    }
}
