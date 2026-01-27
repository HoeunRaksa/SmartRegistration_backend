<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeacherScheduleController extends Controller
{
    /**
     * Get teaching schedule for the teacher
     * GET /api/teacher/schedule
     */
    public function index(Request $request)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $schedules = ClassSchedule::with(['course.majorSubject.subject', 'roomRef.building'])
                ->whereIn('course_id', $courseIds)
                ->get()
                ->map(function($s) {
                    return [
                        'id' => $s->id,
                        'course_name' => $s->course?->majorSubject?->subject?->subject_name,
                        'course_code' => $s->course?->majorSubject?->subject?->subject_code,
                        'day_of_week' => $s->day_of_week,
                        'start_time' => $s->start_time,
                        'end_time' => $s->end_time,
                        'room' => ($s->roomRef?->building?->building_name ?? 'N/A') . ' - ' . ($s->roomRef?->room_number ?? $s->room ?? 'N/A'),
                    ];
                });

            return response()->json(['data' => $schedules], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherScheduleController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load schedule'], 500);
        }
    }
}
