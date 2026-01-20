<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminGradeController extends Controller
{
    /**
     * GET /api/admin/grades
     */
    public function index()
    {
        try {
            $grades = Grade::with(['student', 'course'])
                ->orderByDesc('id')
                ->get()
                ->map(function ($g) {
                    return [
                        'id' => $g->id,
                        'student_id' => $g->student_id,
                        'course_id' => $g->course_id,

                        'assignment_name' => $g->assignment_name ?? null,
                        'score' => $g->score,
                        'total_points' => $g->total_points ?? null,
                        'letter_grade' => $g->letter_grade ?? null,
                        'grade_point' => $g->grade_point ?? null,
                        'feedback' => $g->feedback ?? null,

                        'created_at' => $g->created_at,
                        'updated_at' => $g->updated_at,

                        // extra for UI
                        'student_name' => $g->student->full_name ?? $g->student->name ?? null,
                        'student_code' => $g->student->student_code ?? null,
                        'course_code' => $g->course->course_code ?? null,
                        'course_name' => $g->course->course_name ?? null,
                    ];
                });

            return response()->json(['data' => $grades], 200);
        } catch (\Throwable $e) {
            Log::error('AdminGradeController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load grades'], 500);
        }
    }

    /**
     * POST /api/admin/grades
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'student_id' => 'required|exists:students,id',
                'course_id' => 'required|exists:courses,id',

                'assignment_name' => 'nullable|string|max:255',
                'score' => 'required|numeric|min:0',
                'total_points' => 'nullable|numeric|min:0',

                'letter_grade' => 'nullable|string|max:5',
                'grade_point' => 'nullable|numeric|min:0|max:4',

                'feedback' => 'nullable|string|max:1000',
            ]);

            $grade = Grade::create($data);

            return response()->json([
                'message' => 'Grade created',
                'data' => $grade->load(['student', 'course']),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('AdminGradeController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create grade'], 500);
        }
    }

    /**
     * PUT /api/admin/grades/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $grade = Grade::findOrFail($id);

            $data = $request->validate([
                'student_id' => 'sometimes|required|exists:students,id',
                'course_id' => 'sometimes|required|exists:courses,id',

                'assignment_name' => 'nullable|string|max:255',
                'score' => 'sometimes|required|numeric|min:0',
                'total_points' => 'nullable|numeric|min:0',

                'letter_grade' => 'nullable|string|max:5',
                'grade_point' => 'nullable|numeric|min:0|max:4',

                'feedback' => 'nullable|string|max:1000',
            ]);

            $grade->update($data);

            return response()->json([
                'message' => 'Grade updated',
                'data' => $grade->fresh()->load(['student', 'course']),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminGradeController@update error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update grade'], 500);
        }
    }

    /**
     * DELETE /api/admin/grades/{id}
     */
    public function destroy($id)
    {
        try {
            $grade = Grade::findOrFail($id);
            $grade->delete();

            return response()->json(['message' => 'Grade deleted'], 200);
        } catch (\Throwable $e) {
            Log::error('AdminGradeController@destroy error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete grade'], 500);
        }
    }
}
