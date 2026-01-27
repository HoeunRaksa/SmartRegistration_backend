<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TeacherAssignmentController extends Controller
{
    /**
     * Get all assignments for courses taught by the teacher
     * GET /api/teacher/assignments
     */
    public function index(Request $request)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            $courseIds = Course::where('teacher_id', $teacher->id)->pluck('id');

            $assignments = Assignment::with(['course.majorSubject.subject'])
                ->withCount(['submissions', 'submissions as graded_count' => function($q) {
                    $q->where('status', 'graded');
                }])
                ->whereIn('course_id', $courseIds)
                ->latest()
                ->get()
                ->map(function($a) {
                    $subject = $a->course?->majorSubject?->subject;
                    return [
                        'id' => $a->id,
                        'title' => $a->title,
                        'description' => $a->description,
                        'course' => $subject?->subject_name ?? 'N/A',
                        'courseCode' => $subject?->subject_code ?? 'N/A',
                        'course_id' => $a->course_id,
                        'dueDate' => $a->due_date?->toDateString(),
                        'dueTime' => $a->due_time,
                        'submitted' => $a->submissions_count,
                        'graded' => $a->graded_count,
                        'points' => $a->points,
                        'status' => $a->due_date?->isPast() ? 'expired' : 'active',
                        'color' => 'from-purple-500 to-pink-500' 
                    ];
                });

            return response()->json(['data' => $assignments], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAssignmentController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load assignments'], 500);
        }
    }

    /**
     * Create a new assignment
     * POST /api/teacher/assignments
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id'   => 'required|exists:courses,id',
            'title'       => 'required|string|max:255',
            'description' => 'required|string',
            'points'      => 'required|numeric|min:0',
            'due_date'    => 'required|date',
            'due_time'    => 'nullable|string',
            'attachment'  => 'nullable|file|max:10240', // 10MB
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            
            // Verify ownership
            $course = Course::where('id', $validated['course_id'])
                ->where('teacher_id', $teacher->id)
                ->firstOrFail();

            $attachmentPath = null;
            if ($request->hasFile('attachment')) {
                $attachmentPath = $request->file('attachment')->store('assignments', 'public');
            }

            $assignment = Assignment::create([
                'course_id'       => $validated['course_id'],
                'title'           => $validated['title'],
                'description'     => $validated['description'],
                'points'          => $validated['points'],
                'due_date'        => $validated['due_date'],
                'due_time'        => $validated['due_time'],
                'attachment_path' => $attachmentPath,
            ]);

            return response()->json([
                'message' => 'Assignment created successfully',
                'data' => $assignment
            ], 201);
        } catch (\Throwable $e) {
            Log::error('TeacherAssignmentController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to create assignment'], 500);
        }
    }

    /**
     * Get submissions for an assignment
     * GET /api/teacher/assignments/{id}/submissions
     */
    public function getSubmissions(Request $request, $id)
    {
        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            
            $assignment = Assignment::where('id', $id)
                ->whereHas('course', fn($q) => $q->where('teacher_id', $teacher->id))
                ->firstOrFail();

            $submissions = AssignmentSubmission::with(['student.user'])
                ->where('assignment_id', $id)
                ->get()
                ->map(function($s) {
                    return [
                        'id' => $s->id,
                        'student_name' => $s->student?->full_name,
                        'student_id_code' => $s->student?->student_code,
                        'submitted_at' => $s->submitted_at?->toDateTimeString(),
                        'status' => $s->status,
                        'score' => $s->score,
                        'feedback' => $s->feedback,
                        'file_url' => $s->submission_file_path ? asset('storage/' . $s->submission_file_path) : null,
                        'submission_text' => $s->submission_text,
                    ];
                });

            return response()->json(['data' => $submissions], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAssignmentController@getSubmissions error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load submissions'], 500);
        }
    }

    /**
     * Grade a submission
     * PUT /api/teacher/submissions/{id}/grade
     */
    public function gradeSubmission(Request $request, $id)
    {
        $validated = $request->validate([
            'score'    => 'required|numeric|min:0',
            'feedback' => 'nullable|string',
        ]);

        try {
            $teacher = Teacher::where('user_id', $request->user()->id)->firstOrFail();
            
            $submission = AssignmentSubmission::where('id', $id)
                ->whereHas('assignment.course', fn($q) => $q->where('teacher_id', $teacher->id))
                ->firstOrFail();

            $submission->update([
                'score' => $validated['score'],
                'feedback' => $validated['feedback'],
                'status' => 'graded'
            ]);

            return response()->json([
                'message' => 'Submission graded successfully',
                'data' => $submission
            ], 200);
        } catch (\Throwable $e) {
            Log::error('TeacherAssignmentController@gradeSubmission error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to grade submission'], 500);
        }
    }
}
