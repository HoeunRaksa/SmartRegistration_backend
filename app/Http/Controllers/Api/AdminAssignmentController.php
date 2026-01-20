<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminAssignmentController extends Controller
{
    /**
     * GET /api/admin/assignments
     */
    public function index()
    {
        try {
            $assignments = Assignment::with('course')
                ->orderByDesc('id')
                ->get()
                ->map(function ($a) {
                    $course = $a->course;

                    return [
                        'id' => $a->id,
                        'course_id' => $a->course_id,
                        'title' => $a->title,
                        'description' => $a->description,
                        'points' => $a->points,
                        'due_date' => $a->due_date ? $a->due_date->format('Y-m-d') : null,
                        'due_time' => $a->due_time ? substr((string)$a->due_time, 0, 5) : null,
                        'attachment_path' => $a->attachment_path ?? null,

                        'created_at' => $a->created_at,
                        'updated_at' => $a->updated_at,

                        // extra for UI
                        'course_code' => $course->course_code ?? null,
                        'course_name' => $course->course_name ?? null,
                    ];
                });

            return response()->json(['data' => $assignments], 200);
        } catch (\Throwable $e) {
            Log::error('AdminAssignmentController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load assignments'], 500);
        }
    }

    /**
     * POST /api/admin/assignments
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'course_id' => 'required|exists:courses,id',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'points' => 'required|numeric|min:0',
                'due_date' => 'nullable|date',
                'due_time' => 'nullable',
                'attachment_path' => 'nullable|string|max:255',
            ]);

            $assignment = Assignment::create($data);

            return response()->json([
                'message' => 'Assignment created',
                'data' => $assignment->load('course'),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('AdminAssignmentController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create assignment'], 500);
        }
    }

    /**
     * PUT /api/admin/assignments/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $assignment = Assignment::findOrFail($id);

            $data = $request->validate([
                'course_id' => 'sometimes|required|exists:courses,id',
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'points' => 'sometimes|required|numeric|min:0',
                'due_date' => 'nullable|date',
                'due_time' => 'nullable',
                'attachment_path' => 'nullable|string|max:255',
            ]);

            $assignment->update($data);

            return response()->json([
                'message' => 'Assignment updated',
                'data' => $assignment->fresh()->load('course'),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminAssignmentController@update error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update assignment'], 500);
        }
    }

    /**
     * DELETE /api/admin/assignments/{id}
     */
    public function destroy($id)
    {
        try {
            $assignment = Assignment::findOrFail($id);
            $assignment->delete();

            return response()->json(['message' => 'Assignment deleted'], 200);
        } catch (\Throwable $e) {
            Log::error('AdminAssignmentController@destroy error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete assignment'], 500);
        }
    }

    /**
     * GET /api/admin/assignments/{id}/submissions
     */
    public function submissions($id)
    {
        try {
            $subs = AssignmentSubmission::with(['student', 'assignment'])
                ->where('assignment_id', $id)
                ->orderByDesc('id')
                ->get()
                ->map(function ($s) {
                    return [
                        'id' => $s->id,
                        'assignment_id' => $s->assignment_id,
                        'student_id' => $s->student_id,
                        'submission_text' => $s->submission_text ?? null,
                        'attachment_path' => $s->attachment_path ?? null,
                        'score' => $s->score ?? null,
                        'feedback' => $s->feedback ?? null,
                        'submitted_at' => $s->submitted_at ?? $s->created_at,
                        'created_at' => $s->created_at,
                        'updated_at' => $s->updated_at,

                        // extra for UI
                        'student_name' => $s->student->full_name ?? $s->student->name ?? null,
                        'student_code' => $s->student->student_code ?? null,
                    ];
                });

            return response()->json(['data' => $subs], 200);
        } catch (\Throwable $e) {
            Log::error('AdminAssignmentController@submissions error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load submissions'], 500);
        }
    }

    /**
     * PUT /api/admin/submissions/{id}/grade
     * Body: { score, feedback? }
     */
    public function gradeSubmission(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'score' => 'required|numeric|min:0',
                'feedback' => 'nullable|string|max:1000',
            ]);

            $sub = AssignmentSubmission::with('assignment')->findOrFail($id);

            // Optional: ensure score <= assignment points (if points exists)
            $max = $sub->assignment->points ?? null;
            if ($max !== null && $data['score'] > $max) {
                return response()->json([
                    'message' => "Score cannot exceed assignment points ($max)."
                ], 422);
            }

            $sub->score = $data['score'];
            $sub->feedback = $data['feedback'] ?? $sub->feedback;
            $sub->save();

            return response()->json([
                'message' => 'Submission graded',
                'data' => $sub->fresh()->load(['student', 'assignment']),
            ], 200);
              } catch (\Throwable $e) {
            Log::error('AdminAssignmentController@gradeSubmission error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to grade submission'], 500);
        }

    }
}
