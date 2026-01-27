<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TeacherGradeController extends Controller
{
    /**
     * Get grades for students in courses taught by the teacher
     * GET /api/teacher/grades
     */
    public function index(Request $request)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $grades = Grade::with(['student.user', 'course.majorSubject.subject'])
                ->whereIn('course_id', $courseIds)
                ->get()
                ->map(function($g) {
                    return [
                        'id' => $g->id,
                        'student_name' => $g->student?->full_name,
                        'course_name' => $g->course?->majorSubject?->subject?->subject_name ?? '',
                        'assignment' => $g->assignment_name,
                        'score' => $g->score,
                        'total_points' => $g->total_points,
                        'grade' => $g->letter_grade,
                        'feedback' => $g->feedback
                    ];
                });

            return response()->json(['data' => $grades], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherGradeController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load grades'], 500);
        }
    }

    /**
     * Post or update student grade
     * POST /api/teacher/grades
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id'       => 'required|exists:courses,id',
            'student_id'      => 'required|exists:students,id',
            'assignment_name' => 'required|string|max:255',
            'score'           => 'required|numeric|min:0',
            'total_points'    => 'required|numeric|min:1',
            'feedback'        => 'nullable|string',
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            
            // Verify ownership
            Course::where('id', $validated['course_id'])
                ->where('teacher_id', $teacher->id)
                ->firstOrFail();

            // Calculate letter grade (simple logic)
            $percentage = ($validated['score'] / $validated['total_points']) * 100;
            $letterGrade = $this->calculateLetterGrade($percentage);

            $grade = Grade::updateOrCreate(
                [
                    'course_id'       => $validated['course_id'],
                    'student_id'      => $validated['student_id'],
                    'assignment_name' => $validated['assignment_name'],
                ],
                [
                    'score'        => $validated['score'],
                    'total_points' => $validated['total_points'],
                    'letter_grade' => $letterGrade,
                    'feedback'     => $validated['feedback'],
                ]
            );

            return response()->json([
                'message' => 'Grade saved successfully',
                'data' => $grade
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherGradeController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to save grade'], 500);
        }
    }

    private function calculateLetterGrade($p) {
        if ($p >= 90) return 'A';
        if ($p >= 80) return 'B';
        if ($p >= 70) return 'C';
        if ($p >= 60) return 'D';
        return 'F';
    }
}
