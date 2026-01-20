<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\ClassSession;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAttendanceController extends Controller
{
    /**
     * GET /api/admin/attendance
     * Optional filters:
     *  - course_id
     *  - student_id
     *  - date (YYYY-MM-DD)
     */
    public function index(Request $request)
    {
        try {
            $query = AttendanceRecord::query()
                ->with(['student', 'classSession.course']);

            if ($request->filled('student_id')) {
                $query->where('student_id', $request->student_id);
            }

            if ($request->filled('course_id')) {
                $query->whereHas('classSession', function ($q) use ($request) {
                    $q->where('course_id', $request->course_id);
                });
            }

            if ($request->filled('date')) {
                $query->whereDate('date', $request->date);
            }

            $attendance = $query->orderByDesc('id')->get();

            return response()->json(['data' => $attendance], 200);
        } catch (\Throwable $e) {
            Log::error('AdminAttendanceController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance'], 500);
        }
    }

    /**
     * POST /api/admin/class-sessions
     * Create a class session for a course + date/time.
     */
    public function createSession(Request $request)
    {
        try {
            $data = $request->validate([
                'course_id' => 'required|exists:courses,id',
                'date' => 'required|date',
                'start_time' => 'nullable',
                'end_time' => 'nullable',
                'room' => 'nullable|string|max:100',
                'note' => 'nullable|string|max:255',
            ]);

            $session = ClassSession::create($data);

            return response()->json([
                'message' => 'Class session created',
                'data' => $session->load('course'),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('AdminAttendanceController@createSession error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create class session'], 500);
        }
    }

    /**
     * POST /api/admin/attendance
     * Mark attendance for a student in a class session.
     * Body: { class_session_id, student_id, status, note? }
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'class_session_id' => 'required|exists:class_sessions,id',
                'student_id' => 'required|exists:students,id',
                'status' => 'required|string|in:present,absent,late,excused',
                'note' => 'nullable|string|max:255',
            ]);

            // Prevent duplicates
            $exists = AttendanceRecord::where('class_session_id', $data['class_session_id'])
                ->where('student_id', $data['student_id'])
                ->first();

            if ($exists) {
                // update existing instead of duplicate
                $exists->status = $data['status'];
                $exists->note = $data['note'] ?? $exists->note;
                $exists->save();

                return response()->json([
                    'message' => 'Attendance updated (already existed)',
                    'data' => $exists->load(['student', 'classSession.course']),
                ], 200);
            }

            $attendance = AttendanceRecord::create($data);

            return response()->json([
                'message' => 'Attendance created',
                'data' => $attendance->load(['student', 'classSession.course']),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('AdminAttendanceController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to mark attendance'], 500);
        }
    }

    /**
     * PUT /api/admin/attendance/{id}
     * Update attendance status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'status' => 'required|string|in:present,absent,late,excused',
                'note' => 'nullable|string|max:255',
            ]);

            $attendance = AttendanceRecord::findOrFail($id);
            $attendance->status = $data['status'];
            if (array_key_exists('note', $data)) {
                $attendance->note = $data['note'];
            }
            $attendance->save();

            return response()->json([
                'message' => 'Attendance updated',
                'data' => $attendance->load(['student', 'classSession.course']),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminAttendanceController@updateStatus error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update attendance'], 500);
        }
    }
}
