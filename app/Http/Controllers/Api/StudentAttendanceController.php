<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentAttendanceController extends Controller
{
    public function getAttendance(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $records = AttendanceRecord::with(['classSession.course'])
                ->where('student_id', $student->id)
                ->whereHas('classSession', function ($q) use ($courseIds) {
                    $q->whereIn('course_id', $courseIds);
                })
                ->orderByDesc('id')
                ->get();

            return response()->json(['data' => $records], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getAttendance error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance'], 500);
        }
    }

    public function getStats(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $total = AttendanceRecord::where('student_id', $student->id)->count();

            $present = AttendanceRecord::where('student_id', $student->id)->where('status', 'present')->count();
            $absent = AttendanceRecord::where('student_id', $student->id)->where('status', 'absent')->count();
            $late = AttendanceRecord::where('student_id', $student->id)->where('status', 'late')->count();
            $excused = AttendanceRecord::where('student_id', $student->id)->where('status', 'excused')->count();

            return response()->json([
                'data' => [
                    'total' => $total,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'excused' => $excused,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAttendanceController@getStats error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load attendance stats'], 500);
        }
    }
}
