<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

                    $courseCode = $course->course_code ?? '';
                    $courseName = $course->course_name ?? '';
                    $courseLabel = trim($courseCode . ' - ' . $courseName);
                    if ($courseLabel === '-') $courseLabel = null;
                    if ($courseLabel === '') $courseLabel = null;

                    return [
                        'id' => $item->id,
                        'course_id' => $item->course_id,

                        // easy course info for UI
                        'course_code'  => $course->course_code ?? null,
                        'course_name'  => $course->course_name ?? null,
                        'course_label' => $courseLabel,
                        'instructor'   => $course->instructor ?? ($course->teacher ?? null),

                        'day_of_week' => $item->day_of_week,
                        'start_time'  => substr((string) $item->start_time, 0, 5),
                        'end_time'    => substr((string) $item->end_time, 0, 5),

                        // keep key consistent with DB/backend: room
                        'room'         => $item->room,
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
                'course_id'    => 'required|integer|exists:courses,id',
                'day_of_week'  => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'start_time'   => 'required|date_format:H:i',
                'end_time'     => 'required|date_format:H:i|after:start_time',
                'room'         => 'nullable|string|max:100',
                'session_type' => 'nullable|string|max:50',
            ]);

            $schedule = ClassSchedule::create($validated);

            // return same shape as index (easy UI)
            $schedule->load('course');
            return response()->json([
                'message' => 'Schedule created',
                'data' => [
                    'id'          => $schedule->id,
                    'course_id'   => $schedule->course_id,
                    'course_code' => $schedule->course->course_code ?? null,
                    'course_name' => $schedule->course->course_name ?? null,
                    'course_label'=> trim(($schedule->course->course_code ?? '') . ' - ' . ($schedule->course->course_name ?? '')) ?: null,
                    'instructor'  => $schedule->course->instructor ?? ($schedule->course->teacher ?? null),
                    'day_of_week' => $schedule->day_of_week,
                    'start_time'  => substr((string) $schedule->start_time, 0, 5),
                    'end_time'    => substr((string) $schedule->end_time, 0, 5),
                    'room'        => $schedule->room,
                    'session_type'=> $schedule->session_type,
                ],
            ], 201);
        } catch (ValidationException $e) {
            // keep Laravel validation response
            throw $e;
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

            // IMPORTANT:
            // - We only enforce after:start_time if both are present in the request
            // - Otherwise we do manual compare using existing DB values
            $validated = $request->validate([
                'course_id'    => 'sometimes|required|integer|exists:courses,id',
                'day_of_week'  => 'sometimes|required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'start_time'   => 'sometimes|required|date_format:H:i',
                'end_time'     => 'sometimes|required|date_format:H:i',
                'room'         => 'nullable|string|max:100',
                'session_type' => 'nullable|string|max:50',
            ]);

            // If request includes both start_time and end_time, validate order
            if (array_key_exists('start_time', $validated) && array_key_exists('end_time', $validated)) {
                if ($validated['end_time'] <= $validated['start_time']) {
                    return response()->json(['message' => 'end_time must be after start_time'], 422);
                }
            }

            // If request includes only one time, compare against existing DB time
            if (array_key_exists('start_time', $validated) && !array_key_exists('end_time', $validated)) {
                $existingEnd = substr((string) $schedule->end_time, 0, 5);
                if ($existingEnd <= $validated['start_time']) {
                    return response()->json(['message' => 'end_time must be after start_time'], 422);
                }
            }

            if (!array_key_exists('start_time', $validated) && array_key_exists('end_time', $validated)) {
                $existingStart = substr((string) $schedule->start_time, 0, 5);
                if ($validated['end_time'] <= $existingStart) {
                    return response()->json(['message' => 'end_time must be after start_time'], 422);
                }
            }

            $schedule->update($validated);

            $schedule = $schedule->fresh()->load('course');

            return response()->json([
                'message' => 'Schedule updated',
                'data' => [
                    'id'          => $schedule->id,
                    'course_id'   => $schedule->course_id,
                    'course_code' => $schedule->course->course_code ?? null,
                    'course_name' => $schedule->course->course_name ?? null,
                    'course_label'=> trim(($schedule->course->course_code ?? '') . ' - ' . ($schedule->course->course_name ?? '')) ?: null,
                    'instructor'  => $schedule->course->instructor ?? ($schedule->course->teacher ?? null),
                    'day_of_week' => $schedule->day_of_week,
                    'start_time'  => substr((string) $schedule->start_time, 0, 5),
                    'end_time'    => substr((string) $schedule->end_time, 0, 5),
                    'room'        => $schedule->room,
                    'session_type'=> $schedule->session_type,
                ],
            ], 200);
        } catch (ValidationException $e) {
            throw $e;
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
