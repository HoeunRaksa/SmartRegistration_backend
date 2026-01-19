<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassSchedule;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentScheduleController extends Controller
{
    public function getSchedule(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->get();

            return response()->json(['data' => $schedule], 200);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@getSchedule error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load schedule'], 500);
        }
    }

    public function getTodaySchedule(Request $request)
    {
        try {
            $today = now()->format('l'); // Monday, Tuesday...

            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $schedule = ClassSchedule::with('course')
                ->whereIn('course_id', $courseIds)
                ->where('day_of_week', $today)
                ->orderBy('start_time')
                ->get();

            return response()->json(['data' => $schedule], 200);
        } catch (\Throwable $e) {
            Log::error('StudentScheduleController@getTodaySchedule error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load today schedule'], 500);
        }
    }
}
