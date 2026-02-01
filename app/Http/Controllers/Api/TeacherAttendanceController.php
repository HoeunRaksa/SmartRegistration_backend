<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TeacherAttendanceController extends Controller
{
    /**
     * Get attendance overview for my courses
     * GET /api/teacher/attendance/stats
     */
    public function stats(Request $request)
    {
        try {
            $user = $request->user();
            $teacher = Teacher::where('user_id', $user->id)->first();
            if (!$teacher) {
                return response()->json([
                    'data' => [
                        'total_sessions' => 0,
                        'present' => 0,
                        'absent' => 0,
                        'late' => 0,
                        'attendance_rate' => 100,
                    ]
                ], 200);
            }
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $totalSessions = ClassSession::whereIn('course_id', $courseIds)->count();
            $records = AttendanceRecord::whereHas('classSession', fn($q) => $q->whereIn('course_id', $courseIds))->get();

            $present = $records->where('status', 'present')->count();
            $absent = $records->where('status', 'absent')->count();
            $late = $records->where('status', 'late')->count();

            return response()->json([
                'data' => [
                    'total_sessions' => $totalSessions,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'attendance_rate' => $records->count() > 0 ? round((($present + $late) / $records->count()) * 100, 1) : 100,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAttendanceController@stats error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance stats'], 500);
        }
    }

    /**
     * Get all sessions for teacher courses
     * GET /api/teacher/attendance/sessions
     */
    public function getSessions(Request $request)
    {
        try {
            $user = $request->user();
            $teacher = Teacher::where('user_id', $user->id)->first();
            
            Log::info('TeacherAttendanceController@getSessions - User ID: ' . $user->id);
            
            if (!$teacher) {
                Log::warning('TeacherAttendanceController@getSessions - No teacher found for user_id: ' . $user->id);
                return response()->json(['data' => []], 200);
            }
            
            Log::info('TeacherAttendanceController@getSessions - Teacher ID: ' . $teacher->id);
            
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');
            Log::info('TeacherAttendanceController@getSessions - Course IDs: ' . $courseIds->toJson());

            $now = Carbon::now('Asia/Phnom_Penh');
            $today = $now->toDateString();
            $currentTime = $now->format('H:i');
            
            $sessions = ClassSession::with(['course.majorSubject.subject'])
                ->whereIn('course_id', $courseIds)
                ->orderByDesc('session_date')
                ->get()
                ->map(function($s) use ($today, $currentTime) {
                    $sessionDate = Carbon::parse($s->session_date)->toDateString();
                    $createdDate = $s->created_at ? Carbon::parse($s->created_at)->toDateString() : null;
                    
                    // Check if manually created (created on same day as session)
                    $isManual = $createdDate && $createdDate === $sessionDate;
                    
                    // Check if this is the currently active session
                    $isCurrent = false;
                    if ($sessionDate === $today && $s->start_time && $s->end_time) {
                        $start = Carbon::parse($s->start_time)->subMinutes(15)->format('H:i');
                        $end = Carbon::parse($s->end_time)->addMinutes(60)->format('H:i');
                        $isCurrent = $currentTime >= $start && $currentTime <= $end;
                    }
                    
                    return [
                        'id' => $s->id,
                        'course_id' => $s->course_id,
                        'course_name' => $s->course?->majorSubject?->subject?->subject_name ?? $s->course?->id ?? '',
                        'date' => $s->session_date,
                        'time' => ($s->start_time ?? '') . ' - ' . ($s->end_time ?? ''),
                        'start_time' => $s->start_time,
                        'end_time' => $s->end_time,
                        'room' => $s->room ?? '',
                        'is_manual' => $isManual,
                        'is_current' => $isCurrent,
                        'is_today' => $sessionDate === $today,
                    ];
                });

            Log::info('TeacherAttendanceController@getSessions - Sessions count: ' . $sessions->count());

            return response()->json(['data' => $sessions], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAttendanceController@getSessions error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(['message' => 'Failed to load sessions'], 500);
        }
    }

    /**
     * Mark attendance for a full class
     * POST /api/teacher/attendance/mark
     */
    public function markBulk(Request $request)
    {
        $validated = $request->validate([
            'class_session_id' => 'required|exists:class_sessions,id',
            'attendance'       => 'required|array',
            'attendance.*.student_id' => 'required|exists:students,id',
            'attendance.*.status'      => 'required|string|in:present,absent,late,excused',
            'attendance.*.remarks'    => 'nullable|string',
            'attendance.*.notes'      => 'nullable|string',
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            $session = ClassSession::where('id', $validated['class_session_id'])
                ->whereHas('course', fn($q) => $q->where('teacher_id', $teacher->id))
                ->firstOrFail();

            // 🔥 TIME ENFORCEMENT - Skip for manually created sessions
            $now = Carbon::now('Asia/Phnom_Penh'); 
            $today = $now->toDateString();
            $currentTime = $now->format('H:i');
            
            $sessionDate = Carbon::parse($session->session_date)->toDateString();
            
            // Check if this is a manual session created by teacher (created today or has teacher_created flag)
            $isManualSession = $session->created_at && 
                               Carbon::parse($session->created_at)->toDateString() === $sessionDate;
            
            // Only enforce strict time rules for auto-generated system sessions
            if (!$isManualSession) {
                if ($sessionDate !== $today) {
                    return response()->json(['message' => 'Attendance can only be marked on the day of class.'], 403);
                }

                $startTime = Carbon::parse($session->start_time)->subMinutes(15)->format('H:i');
                $endTime = Carbon::parse($session->end_time)->addMinutes(60)->format('H:i'); // 1 hour grace period

                if ($currentTime < $startTime || $currentTime > $endTime) {
                    return response()->json([
                        'message' => "Attendance window closed. Marking is only allowed between " . 
                                    Carbon::parse($session->start_time)->format('H:i') . " and " . 
                                    Carbon::parse($session->end_time)->format('H:i')
                    ], 403);
                }
            }

            foreach ($validated['attendance'] as $item) {
                AttendanceRecord::updateOrCreate(
                    [
                        'class_session_id' => $session->id,
                        'student_id'       => $item['student_id'],
                    ],
                    [
                        'status' => $item['status'],
                        'notes'  => $item['notes'] ?? $item['remarks'] ?? null,
                    ]
                );
            }

            return response()->json(['message' => 'Attendance marked successfully'], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAttendanceController@markBulk error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to mark attendance'], 500);
        }
    }

    /**
     * Update a single attendance record
     * PUT /api/teacher/attendance/{id}
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:present,absent,late,excused',
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();

            $record = AttendanceRecord::where('id', $id)
                ->whereHas('classSession.course', fn($q) => $q->where('teacher_id', $teacher->id))
                ->firstOrFail();

            $record->update(['status' => $validated['status']]);

            return response()->json([
                'message' => 'Attendance updated successfully',
                'data' => $record
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAttendanceController@update error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update attendance'], 500);
        }
    }

    /**
     * Create a new class session for a teacher's course
     * POST /api/teacher/class-sessions
     */
    public function createSession(Request $request)
    {
        $validated = $request->validate([
            'course_id'    => 'required|exists:courses,id',
            'session_date' => 'required|date',
            'start_time'   => 'required|string',
            'end_time'     => 'required|string',
            'room'         => 'nullable|string|max:255',
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();

            // Verify teacher owns this course
            Course::where('id', $validated['course_id'])
                ->where('teacher_id', $teacher->id)
                ->firstOrFail();

            $session = ClassSession::firstOrCreate([
                'course_id'    => $validated['course_id'],
                'session_date' => $validated['session_date'],
                'start_time'   => $validated['start_time'],
            ], [
                'end_time'     => $validated['end_time'],
                'room'         => $validated['room'] ?? null,
            ]);

            return response()->json([
                'message' => 'Class session created successfully',
                'data' => $session
            ], 201);
        } catch (\Throwable $e) {
            Log::error('TeacherAttendanceController@createSession error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create class session'], 500);
        }
    }
}
