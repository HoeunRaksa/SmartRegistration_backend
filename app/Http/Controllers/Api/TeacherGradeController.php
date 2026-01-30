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
            $user = $request->user();
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $teacher = Teacher::where('user_id', $user->id)->first();
            if (!$teacher) {
                Log::warning('TeacherGradeController@index: Teacher record not found for user_id ' . $user->id);
                return response()->json(['data' => []], 200);
            }

            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');
            if ($courseIds->isEmpty()) {
                return response()->json(['data' => []], 200);
            }

            $grades = Grade::with(['student.user', 'course.majorSubject.subject'])
                ->whereIn('course_id', $courseIds->toArray())
                ->get()
                ->map(function($g) {
                    $studentName = $g->student ? ($g->student->full_name_en ?: $g->student->full_name_kh ?: $g->student->student_code) : 'Unknown Student';
                    $courseName = $g->course?->majorSubject?->subject?->subject_name ?? ('Course #' . $g->course_id);

                    return [
                        'id' => $g->id,
                        'student_id' => $g->student_id,
                        'student_name' => $studentName,
                        'course_id' => $g->course_id,
                        'course_name' => $courseName,
                        'assignment' => $g->assignment_name ?? '',
                        'assignment_name' => $g->assignment_name ?? '',
                        'score' => (float)($g->score ?? 0),
                        'total_points' => (float)($g->total_points ?? 100),
                        'grade' => $g->letter_grade ?? '',
                        'letter_grade' => $g->letter_grade ?? '',
                        'feedback' => $g->feedback ?? '',
                    ];
                });

            return response()->json(['data' => $grades], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherGradeController@index error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Failed to load grades: ' . $e->getMessage()], 500);
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

    /**
     * Update an existing grade
     * PUT /api/teacher/grades/{id}
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'course_id'       => 'sometimes|required|exists:courses,id',
            'student_id'      => 'sometimes|required|exists:students,id',
            'assignment_name' => 'sometimes|required|string|max:255',
            'score'           => 'required|numeric|min:0',
            'total_points'    => 'required|numeric|min:1',
            'feedback'        => 'nullable|string',
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();

            $grade = Grade::findOrFail($id);

            // Verify teacher owns the course this grade belongs to
            Course::where('id', $grade->course_id)
                ->where('teacher_id', $teacher->id)
                ->firstOrFail();

            $percentage = ($validated['score'] / $validated['total_points']) * 100;
            $letterGrade = $this->calculateLetterGrade($percentage);

            $grade->update([
                'score'        => $validated['score'],
                'total_points' => $validated['total_points'],
                'letter_grade' => $letterGrade,
                'feedback'     => $validated['feedback'] ?? $grade->feedback,
                'assignment_name' => $validated['assignment_name'] ?? $grade->assignment_name,
            ]);

            return response()->json([
                'message' => 'Grade updated successfully',
                'data' => $grade
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherGradeController@update error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update grade'], 500);
        }
    }

    /**
     * Delete a grade
     * DELETE /api/teacher/grades/{id}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();

            $grade = Grade::findOrFail($id);

            // Verify teacher owns the course this grade belongs to
            Course::where('id', $grade->course_id)
                ->where('teacher_id', $teacher->id)
                ->firstOrFail();

            $grade->delete();

            return response()->json(['message' => 'Grade deleted successfully'], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherGradeController@destroy error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete grade'], 500);
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
