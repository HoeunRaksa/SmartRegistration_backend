<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentGradeController extends Controller
{
    public function getGrades(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $grades = Grade::with('course')
                ->where('student_id', $student->id)
                ->orderByDesc('id')
                ->get();

            return response()->json(['data' => $grades], 200);
        } catch (\Throwable $e) {
            Log::error('StudentGradeController@getGrades error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load grades'], 500);
        }
    }

    public function getGpa(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $gradePoints = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->pluck('grade_point');

            if ($gradePoints->count() === 0) {
                return response()->json(['data' => ['gpa' => 0]], 200);
            }

            $gpa = round($gradePoints->avg(), 2);

            return response()->json(['data' => ['gpa' => $gpa]], 200);
        } catch (\Throwable $e) {
            Log::error('StudentGradeController@getGpa error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to calculate GPA'], 500);
        }
    }
}
