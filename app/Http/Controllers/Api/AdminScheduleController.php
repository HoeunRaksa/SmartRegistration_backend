<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class AdminScheduleController extends Controller
{
    /**
     * GET /api/admin/schedules
     * Get all schedules with course details (+ room_id support)
     */
public function index(Request $request)
{
    try {
        $schedules = ClassSchedule::with([
                'course.majorSubject.subject',
                'course.classGroup',
                'course.teacher',
                'roomRef.building' // ✅ Use the renamed relationship
            ])
            ->orderByRaw("FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
            ->orderBy('start_time')
            ->get()
            ->map(function ($schedule) {
                $course = $schedule->course;
                $room = $schedule->roomRef; // ✅ Access renamed relationship
                $building = $room?->building;

                // Build course label
                $courseLabel = $this->buildCourseLabel($course);

                // Get instructor
                $instructor = $course->teacher?->name ?? 
                             $course->teacher?->full_name ?? 
                             $course->instructor ?? 
                             'N/A';

                // ✅ Build room display info
                $roomDisplay = null;
                $buildingCode = null;
                $roomNumber = null;
                
                if ($room) {
                    $buildingCode = $building?->building_code;
                    $roomNumber = $room->room_number;
                    
                    // Full room name: "A-101" or just "101" if no building
                    $roomDisplay = $buildingCode 
                        ? "{$buildingCode}-{$roomNumber}" 
                        : $roomNumber;
                }

                return [
                    'id' => $schedule->id,
                    'course_id' => $schedule->course_id,

                    // Course info
                    'course_label' => $courseLabel,
                    'instructor' => $instructor,
                    'shift' => $course->classGroup?->shift ?? null,
                    'class_name' => $course->classGroup?->class_name ?? null,

                    // Schedule details
                    'day_of_week' => $schedule->day_of_week,
                    'start_time' => substr((string) $schedule->start_time, 0, 5),
                    'end_time' => substr((string) $schedule->end_time, 0, 5),

                    // ✅ Room info (both legacy and new)
                    'room' => $schedule->room, // Legacy string column (for backward compatibility)
                    'room_id' => $schedule->room_id, // FK to rooms table
                    'room_number' => $roomNumber, // Just the room number
                    'building_code' => $buildingCode, // Just the building code
                    'room_full_name' => $roomDisplay, // "A-101" format
                    'building_id' => $room?->building_id, // For editing

                    'session_type' => $schedule->session_type,

                    // Timestamps
                    'created_at' => $schedule->created_at,
                    'updated_at' => $schedule->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $schedules,
            'total' => $schedules->count(),
        ], 200);

    } catch (\Throwable $e) {
        Log::error('AdminScheduleController@index error: ' . $e->getMessage());
        Log::error('Stack trace: ' . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to load schedules',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * POST /api/admin/schedules
     * Create a new schedule (+ room_id support)
     */
public function store(Request $request)
{
    try {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'day_of_week' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'room_id' => 'nullable|integer|exists:rooms,id',
            'session_type' => 'nullable|string|max:50',
        ]);

        DB::beginTransaction();

        $schedule = ClassSchedule::create($validated);

        DB::commit();

        // ✅ Load relationships including room
        $schedule->load([
            'course.majorSubject.subject',
            'course.classGroup',
            'course.teacher',
            'roomRef.building'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Schedule created successfully',
            'data' => $this->formatScheduleResponse($schedule),
        ], 201);

    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('AdminScheduleController@store error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to create schedule',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * PUT /api/admin/schedules/{id}
     * Update an existing schedule (+ room_id support)
     */
public function update(Request $request, $id)
{
    try {
        $schedule = ClassSchedule::findOrFail($id);

        $validated = $request->validate([
            'course_id' => 'sometimes|required|integer|exists:courses,id',
            'day_of_week' => 'sometimes|required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'start_time' => 'sometimes|required|date_format:H:i',
            'end_time' => 'sometimes|required|date_format:H:i',
            'room_id' => 'nullable|integer|exists:rooms,id', // ✅ Validate room FK
            'session_type' => 'nullable|string|max:50',
        ]);

        // Validate time order
        $startTime = $validated['start_time'] ?? substr((string) $schedule->start_time, 0, 5);
        $endTime = $validated['end_time'] ?? substr((string) $schedule->end_time, 0, 5);

        if ($endTime <= $startTime) {
            return response()->json([
                'success' => false,
                'message' => 'End time must be after start time'
            ], 422);
        }

        DB::beginTransaction();

        $schedule->update($validated);

        DB::commit();

        // ✅ Reload with relationships
        $schedule = $schedule->fresh([
            'course.majorSubject.subject',
            'course.classGroup',
            'course.teacher',
            'roomRef.building'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Schedule updated successfully',
            'data' => $this->formatScheduleResponse($schedule),
        ], 200);

    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $e->errors()
        ], 422);

    } catch (\Throwable $e) {
        DB::rollBack();
        Log::error('AdminScheduleController@update error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Failed to update schedule',
            'error' => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * DELETE /api/admin/schedules/{id}
     * Delete a schedule
     */
    public function destroy($id)
    {
        try {
            $schedule = ClassSchedule::findOrFail($id);

            DB::beginTransaction();

            $schedule->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Schedule deleted successfully'
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('AdminScheduleController@destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete schedule',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/schedules/conflicts
     * Check for schedule conflicts for a course (+ optional room_id)
     */
    public function checkConflicts(Request $request)
    {
        try {
            $validated = $request->validate([
                'course_id' => 'required|integer|exists:courses,id',
                'day_of_week' => 'required|string',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i',
                'exclude_id' => 'nullable|integer',
                'room_id' => 'nullable|integer|exists:rooms,id',
            ]);

            $conflict = $this->checkScheduleConflict(
                $validated['course_id'],
                $validated['day_of_week'],
                $validated['start_time'],
                $validated['end_time'],
                $validated['exclude_id'] ?? null,
                $validated['room_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'has_conflict' => !is_null($conflict),
                'conflict' => $conflict
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@checkConflicts error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check conflicts',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/schedules/by-day/{day}
     * Get schedules for a specific day
     */
    public function getByDay($day)
    {
        try {
            $validDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

            if (!in_array($day, $validDays)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid day of week'
                ], 422);
            }

            $schedules = ClassSchedule::with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'course.teacher'
                ])
                ->where('day_of_week', $day)
                ->orderBy('start_time')
                ->get()
                ->map(fn($s) => $this->formatScheduleResponse($s));

            return response()->json([
                'success' => true,
                'day' => $day,
                'data' => $schedules,
                'total' => $schedules->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@getByDay error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load schedules',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/admin/schedules/by-course/{courseId}
     * Get all schedules for a specific course
     */
    public function getByCourse($courseId)
    {
        try {
            $schedules = ClassSchedule::with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'course.teacher'
                ])
                ->where('course_id', $courseId)
                ->orderByRaw("FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
                ->orderBy('start_time')
                ->get()
                ->map(fn($s) => $this->formatScheduleResponse($s));

            return response()->json([
                'success' => true,
                'course_id' => $courseId,
                'data' => $schedules,
                'total' => $schedules->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@getByCourse error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load schedules',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ========================================
       PRIVATE HELPER METHODS
       ======================================== */

    /**
     * Check for schedule conflicts
     *
     * Current enforced conflict rule (same as your original):
     * - conflict if overlapping time on same day for the same class_group_id
     *
     * Optional additional rule (enabled here only if $roomId is provided):
     * - conflict if overlapping time on same day for the same room_id
     */
    private function checkScheduleConflict($courseId, $dayOfWeek, $startTime, $endTime, $excludeId = null, $roomId = null)
    {
        $course = Course::with('classGroup')->find($courseId);

        if (!$course || !$course->classGroup) {
            return null;
        }

        // (A) Conflict within same class group (original behavior)
        $query = ClassSchedule::whereHas('course', function ($q) use ($course) {
                $q->where('class_group_id', $course->class_group_id);
            })
            ->where('day_of_week', $dayOfWeek)
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where('start_time', '<', $endTime)
                  ->where('end_time', '>', $startTime);
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $conflict = $query->with('course.majorSubject.subject')->first();

        if ($conflict) {
            return [
                'type' => 'class_group',
                'schedule_id' => $conflict->id,
                'course' => $this->buildCourseLabel($conflict->course),
                'day' => $conflict->day_of_week,
                'time' => substr((string) $conflict->start_time, 0, 5) . ' - ' . substr((string) $conflict->end_time, 0, 5),
                'room' => $conflict->room,
                'room_id' => $conflict->room_id,
            ];
        }

        // (B) Optional: room conflict (only when room_id provided)
        if (!is_null($roomId)) {
            $roomQuery = ClassSchedule::where('room_id', $roomId)
                ->where('day_of_week', $dayOfWeek)
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<', $endTime)
                      ->where('end_time', '>', $startTime);
                });

            if ($excludeId) {
                $roomQuery->where('id', '!=', $excludeId);
            }

            $roomConflict = $roomQuery->with('course.majorSubject.subject')->first();

            if ($roomConflict) {
                return [
                    'type' => 'room',
                    'schedule_id' => $roomConflict->id,
                    'course' => $this->buildCourseLabel($roomConflict->course),
                    'day' => $roomConflict->day_of_week,
                    'time' => substr((string) $roomConflict->start_time, 0, 5) . ' - ' . substr((string) $roomConflict->end_time, 0, 5),
                    'room' => $roomConflict->room,
                    'room_id' => $roomConflict->room_id,
                ];
            }
        }

        return null;
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
     * Format schedule response
     */
    private function formatScheduleResponse($schedule)
    {
        $course = $schedule->course;

        return [
            'id' => $schedule->id,
            'course_id' => $schedule->course_id,
            'course_label' => $this->buildCourseLabel($course),
            'instructor' => $course->teacher?->name ??
                           $course->teacher?->full_name ??
                           $course->instructor ??
                           'N/A',
            'shift' => $course->classGroup?->shift ?? null,
            'class_name' => $course->classGroup?->class_name ?? null,
            'day_of_week' => $schedule->day_of_week,
            'start_time' => substr((string) $schedule->start_time, 0, 5),
            'end_time' => substr((string) $schedule->end_time, 0, 5),

            // Room fields (support both legacy + FK)
            'room' => $schedule->room,
            'room_id' => $schedule->room_id,

            'session_type' => $schedule->session_type,
            'created_at' => $schedule->created_at,
            'updated_at' => $schedule->updated_at,
        ];
    }
}
