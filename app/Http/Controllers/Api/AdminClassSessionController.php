<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSession;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class AdminClassSessionController extends Controller
{
    /**
     * GET /api/admin/class-sessions
     * Get all class sessions with filters
     */
    public function index(Request $request)
    {
        try {
            $query = ClassSession::with([
                'course.majorSubject.subject',
                'course.classGroup',
                'course.teacher'
            ]);

            // Filter by date range
            if ($request->start_date) {
                $query->where('session_date', '>=', $request->start_date);
            }
            if ($request->end_date) {
                $query->where('session_date', '<=', $request->end_date);
            }

            // Filter by course
            if ($request->course_id) {
                $query->where('course_id', $request->course_id);
            }

            // Filter by specific date
            if ($request->date) {
                $query->whereDate('session_date', $request->date);
            }

            // Filter by day of week (MySQL DAYNAME)
            if ($request->day_of_week) {
                $query->whereRaw('DAYNAME(session_date) = ?', [$request->day_of_week]);
            }

            $sessions = $query
                ->orderBy('session_date', 'desc')
                ->orderBy('start_time')
                ->get()
                ->map(function ($session) {
                    return $this->formatSessionResponse($session);
                });

            return response()->json([
                'success' => true,
                'data' => $sessions,
                'total' => $sessions->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminClassSessionController@index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load class sessions',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/class-sessions/{id}
     * Get single class session with attendance
     */
    public function show($id)
    {
        try {
            $session = ClassSession::with([
                'course.majorSubject.subject',
                'course.classGroup',
                'course.teacher',
                'attendanceRecords.student'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'session' => $this->formatSessionResponse($session),
                    'attendance_count' => [
                        'total' => $session->attendanceRecords->count(),
                        'present' => $session->attendanceRecords->where('status', 'present')->count(),
                        'absent' => $session->attendanceRecords->where('status', 'absent')->count(),
                        'late' => $session->attendanceRecords->where('status', 'late')->count(),
                    ],
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminClassSessionController@show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load class session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * POST /api/admin/class-sessions
     * Create a single class session manually
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'course_id' => 'required|integer|exists:courses,id',
                'session_date' => 'required|date',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',

                // Support both legacy room string + new room_id FK
                'room' => 'nullable|string|max:100',
                'room_id' => 'nullable|integer|exists:rooms,id',

                'session_type' => 'nullable|string|max:50',
            ]);

            // Check for duplicate (stronger key: include room_id if present, else room string)
            $dupQuery = ClassSession::where('course_id', $validated['course_id'])
                ->where('session_date', $validated['session_date'])
                ->where('start_time', $validated['start_time']);

            if (!empty($validated['room_id'])) {
                $dupQuery->where('room_id', $validated['room_id']);
            } else {
                $dupQuery->where('room', $validated['room'] ?? null);
            }

            if ($dupQuery->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A session already exists for this course at this date and time'
                ], 422);
            }

            DB::beginTransaction();

            $session = ClassSession::create($validated);

            DB::commit();

            $session->load(['course.majorSubject.subject', 'course.classGroup', 'course.teacher']);

            return response()->json([
                'success' => true,
                'message' => 'Class session created successfully',
                'data' => $this->formatSessionResponse($session),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminClassSessionController@store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create class session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * PUT /api/admin/class-sessions/{id}
     * Update a class session
     */
    public function update(Request $request, $id)
    {
        try {
            $session = ClassSession::findOrFail($id);

            $validated = $request->validate([
                'course_id' => 'sometimes|required|integer|exists:courses,id',
                'session_date' => 'sometimes|required|date',
                'start_time' => 'sometimes|required|date_format:H:i',
                'end_time' => 'sometimes|required|date_format:H:i',

                // Support both legacy room string + new room_id FK
                'room' => 'nullable|string|max:100',
                'room_id' => 'nullable|integer|exists:rooms,id',

                'session_type' => 'nullable|string|max:50',
            ]);

            $startTime = $validated['start_time'] ?? substr((string) $session->start_time, 0, 5);
            $endTime   = $validated['end_time'] ?? substr((string) $session->end_time, 0, 5);

            if ($endTime <= $startTime) {
                return response()->json([
                    'success' => false,
                    'message' => 'End time must be after start time'
                ], 422);
            }

            DB::beginTransaction();

            $session->update($validated);

            DB::commit();

            $session = $session->fresh([
                'course.majorSubject.subject',
                'course.classGroup',
                'course.teacher'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Class session updated successfully',
                'data' => $this->formatSessionResponse($session),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminClassSessionController@update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update class session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/class-sessions/{id}
     * Delete a class session
     */
    public function destroy($id)
    {
        try {
            $session = ClassSession::findOrFail($id);

            if ($session->attendanceRecords()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete session with attendance records. Please delete attendance records first.'
                ], 422);
            }

            DB::beginTransaction();

            $session->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Class session deleted successfully'
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminClassSessionController@destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete class session',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * POST /api/admin/class-sessions/generate
     * Generate class sessions from schedules
     */
    public function generate(Request $request)
    {
        try {
            $validated = $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
                'course_id' => 'nullable|integer|exists:courses,id',
                'overwrite' => 'nullable|boolean',
            ]);

            $startDate = Carbon::parse($validated['start_date'])->startOfDay();
            $endDate   = Carbon::parse($validated['end_date'])->endOfDay();
            $overwrite = (bool) ($validated['overwrite'] ?? false);

            $query = ClassSchedule::query();

            if (!empty($validated['course_id'])) {
                $query->where('course_id', $validated['course_id']);
            }

            $schedules = $query->get();

            if ($schedules->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No schedules found to generate sessions from'
                ], 422);
            }

            DB::beginTransaction();

            $totalGenerated = 0;
            $totalSkipped = 0;
            $details = [];

            foreach ($schedules as $schedule) {
                $result = $this->generateSessionsForSchedule($schedule, $startDate, $endDate, $overwrite);
                $totalGenerated += $result['generated'];
                $totalSkipped += $result['skipped'];

                $details[] = [
                    'course_id' => $schedule->course_id,
                    'course_label' => $this->buildCourseLabel($schedule->course),
                    'day_of_week' => $schedule->day_of_week,
                    'generated' => $result['generated'],
                    'skipped' => $result['skipped'],
                ];
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Generated {$totalGenerated} class sessions, skipped {$totalSkipped} existing",
                'summary' => [
                    'total_generated' => $totalGenerated,
                    'total_skipped' => $totalSkipped,
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'details' => $details,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminClassSessionController@generate error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate class sessions',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * DELETE /api/admin/class-sessions/bulk-delete
     * Bulk delete class sessions
     */
    public function bulkDelete(Request $request)
    {
        try {
            $validated = $request->validate([
                'session_ids' => 'required|array',
                'session_ids.*' => 'integer|exists:class_sessions,id',
            ]);

            $sessions = ClassSession::whereIn('id', $validated['session_ids'])->get();

            $withAttendance = $sessions->filter(function ($session) {
                return $session->attendanceRecords()->exists();
            });

            if ($withAttendance->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => count($withAttendance) . ' session(s) have attendance records and cannot be deleted',
                    'sessions_with_attendance' => $withAttendance->pluck('id'),
                ], 422);
            }

            DB::beginTransaction();

            $deleted = ClassSession::whereIn('id', $validated['session_ids'])->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Deleted {$deleted} class session(s) successfully",
                'deleted_count' => $deleted,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminClassSessionController@bulkDelete error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete class sessions',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/class-sessions/upcoming
     * Get upcoming class sessions
     */
    public function upcoming(Request $request)
    {
        try {
            $days = (int) ($request->days ?? 7);

            $start = Carbon::today();
            $end = Carbon::today()->addDays($days);

            $sessions = ClassSession::with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'course.teacher'
                ])
                ->where('session_date', '>=', $start)
                ->where('session_date', '<=', $end)
                ->orderBy('session_date')
                ->orderBy('start_time')
                ->get()
                ->map(function ($session) {
                    return $this->formatSessionResponse($session);
                });

            return response()->json([
                'success' => true,
                'data' => $sessions,
                'total' => $sessions->count(),
                'date_range' => [
                    'start' => $start->format('Y-m-d'),
                    'end' => $end->format('Y-m-d'),
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminClassSessionController@upcoming error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load upcoming sessions',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/class-sessions/by-date/{date}
     * Get all sessions for a specific date
     */
    public function byDate($date)
    {
        try {
            $carbonDate = Carbon::parse($date);

            $sessions = ClassSession::with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'course.teacher'
                ])
                ->whereDate('session_date', $carbonDate)
                ->orderBy('start_time')
                ->get()
                ->map(function ($session) {
                    return $this->formatSessionResponse($session);
                });

            return response()->json([
                'success' => true,
                'date' => $carbonDate->format('Y-m-d'),
                'day_of_week' => $carbonDate->format('l'),
                'data' => $sessions,
                'total' => $sessions->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminClassSessionController@byDate error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load sessions for date',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/class-sessions/by-course/{courseId}
     * Get all sessions for a specific course
     */
    public function byCourse($courseId)
    {
        try {
            $sessions = ClassSession::with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'course.teacher'
                ])
                ->where('course_id', $courseId)
                ->orderBy('session_date', 'desc')
                ->orderBy('start_time')
                ->get()
                ->map(function ($session) {
                    return $this->formatSessionResponse($session);
                });

            return response()->json([
                'success' => true,
                'course_id' => $courseId,
                'data' => $sessions,
                'total' => $sessions->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminClassSessionController@byCourse error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load sessions for course',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ========================================
       PRIVATE HELPER METHODS
       ======================================== */

    /**
     * Generate sessions for a specific schedule
     */
    private function generateSessionsForSchedule($schedule, $startDate, $endDate, $overwrite = false)
    {
        $generated = 0;
        $skipped = 0;
        $current = $startDate->copy();

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

        if (!$targetDay) {
            return ['generated' => 0, 'skipped' => 0];
        }

        while ($current->dayOfWeek !== $targetDay) {
            $current->addDay();
        }

        while ($current->lte($endDate)) {
            $sessionDate = $current->toDateString();

            // Stronger matching: include room_id if present, else fallback to room string
            $existingQuery = ClassSession::where('course_id', $schedule->course_id)
                ->where('session_date', $sessionDate)
                ->where('start_time', $schedule->start_time);

            if (!is_null($schedule->room_id)) {
                $existingQuery->where('room_id', $schedule->room_id);
            } else {
                $existingQuery->where('room', $schedule->room);
            }

            $existing = $existingQuery->first();

            $payload = [
                'course_id' => $schedule->course_id,
                'session_date' => $sessionDate,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'session_type' => $schedule->session_type,

                // Keep both for compatibility; requires columns/fillable on ClassSession
                'room' => $schedule->room,
                'room_id' => $schedule->room_id,
            ];

            if ($existing) {
                if ($overwrite) {
                    $existing->update($payload);
                    $generated++;
                } else {
                    $skipped++;
                }
            } else {
                ClassSession::create($payload);
                $generated++;
            }

            $current->addWeek();
        }

        return [
            'generated' => $generated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Build course label from course data
     */
    private function buildCourseLabel($course)
    {
        if (!$course) {
            return 'N/A';
        }

        $parts = [];

        if ($course->majorSubject && $course->majorSubject->subject) {
            $parts[] = $course->majorSubject->subject->subject_name;
        }

        if ($course->classGroup && $course->classGroup->class_name) {
            $parts[] = $course->classGroup->class_name;
        }

        if ($course->academic_year) {
            $parts[] = $course->academic_year;
        }

        if ($course->semester) {
            $parts[] = 'Sem ' . $course->semester;
        }

        return !empty($parts) ? implode(' — ', $parts) : 'Course #' . $course->id;
    }

    /**
     * Format session response
     */
    private function formatSessionResponse($session)
    {
        $course = $session->course;

        return [
            'id' => $session->id,
            'course_id' => $session->course_id,
            'course_label' => $this->buildCourseLabel($course),
            'instructor' => $course->teacher?->name ??
                           $course->teacher?->full_name ??
                           $course->instructor ??
                           'N/A',
            'shift' => $course->classGroup?->shift ?? null,
            'class_name' => $course->classGroup?->class_name ?? null,
            'session_date' => $session->session_date ? $session->session_date->format('Y-m-d') : null,
            'day_of_week' => $session->session_date ? $session->session_date->format('l') : null,
            'start_time' => substr((string) $session->start_time, 0, 5),
            'end_time' => substr((string) $session->end_time, 0, 5),

            // Room fields (support both legacy + FK)
            'room' => $session->room,
            'room_id' => $session->room_id,

            'session_type' => $session->session_type,
            'has_attendance' => $session->attendanceRecords()->exists(),
            'attendance_count' => $session->attendanceRecords()->count(),
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at,
        ];
    }
}
