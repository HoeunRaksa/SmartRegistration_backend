<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminScheduleController extends Controller
{
    /**
     * GET /api/admin/schedules
     * List all schedules (admin/staff)
     */
    public function index(Request $request)
    {
        try {
            $schedules = ClassSchedule::with('course')
                ->orderByRaw("FIELD(day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')")
                ->orderBy('start_time')
                ->get()
                ->map(function ($item) {
                    $course = $item->course;

                    return [
                        'id' => $item->id,
                        'course_id' => $item->course_id,

                        // course info (useful for admin UI)
                        'course_code' => $course->course_code ?? null,
                        'course_name' => $course->course_name ?? null,
                        'instructor'  => $course->instructor ?? ($course->teacher ?? null),

                        'day' => $item->day_of_week,
                        'day_of_week' => $item->day_of_week,

                        'start_time' => substr((string)$item->start_time, 0, 5),
                        'end_time'   => substr((string)$item->end_time, 0, 5),

                        'room' => $item->room,
                        'session_type' => $item->session_type,
                    ];
                });

            return response()->json(['data' => $schedules], 200);
        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load schedules'], 500);
        }
    }

    /**
     * POST /api/admin/schedules
     * Create schedule
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'course_id' => 'required|exists:courses,id',
                'day_of_week' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'room' => 'nullable|string|max:100',
                'session_type' => 'nullable|string|max:50',
            ]);

            $schedule = ClassSchedule::create($validated);

            return response()->json([
                'message' => 'Schedule created',
                'data' => $schedule->load('course'),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create schedule'], 500);
        }
    }

    /**
     * PUT /api/admin/schedules/{id}
     * Update schedule
     */
    public function update(Request $request, $id)
    {
        try {
            $schedule = ClassSchedule::findOrFail($id);

            $validated = $request->validate([
                'course_id' => 'sometimes|required|exists:courses,id',
                'day_of_week' => 'sometimes|required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'start_time' => 'sometimes|required|date_format:H:i',
                'end_time' => 'sometimes|required|date_format:H:i',
                'room' => 'nullable|string|max:100',
                'session_type' => 'nullable|string|max:50',
            ]);

            // If both times exist, ensure end_time > start_time
            $start = $validated['start_time'] ?? substr((string)$schedule->start_time, 0, 5);
            $end   = $validated['end_time'] ?? substr((string)$schedule->end_time, 0, 5);

            if (isset($validated['start_time']) || isset($validated['end_time'])) {
                if ($end <= $start) {
                    return response()->json(['message' => 'end_time must be after start_time'], 422);
                }
            }

            $schedule->update($validated);

            return response()->json([
                'message' => 'Schedule updated',
                'data' => $schedule->fresh()->load('course'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@update error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update schedule'], 500);
        }
    }

    /**
     * DELETE /api/admin/schedules/{id}
     * Delete schedule
     */
    public function destroy($id)
    {
        try {
            $schedule = ClassSchedule::findOrFail($id);
            $schedule->delete();

            return response()->json(['message' => 'Schedule deleted'], 200);
        } catch (\Throwable $e) {
            Log::error('AdminScheduleController@destroy error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete schedule'], 500);
        }
    }
}
