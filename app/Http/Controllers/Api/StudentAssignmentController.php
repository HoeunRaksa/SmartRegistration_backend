<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class StudentAssignmentController extends Controller
{
    /**
     * Get all assignments for enrolled courses
     * GET /api/student/assignments
     */
    public function getAssignments(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $assignments = Assignment::with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'submissions' => function ($q) use ($student) {
                        $q->where('student_id', $student->id);
                    }
                ])
                ->whereIn('course_id', $courseIds)
                ->orderBy('due_date')
                ->get()
                ->map(function ($assignment) use ($student) {
                    $subject = $assignment->course?->majorSubject?->subject;
                    $submission = $assignment->submissions->first();
                    $dueDate = $assignment->due_date ? Carbon::parse($assignment->due_date) : null;

                    return [
                        'id' => $assignment->id,
                        'title' => $assignment->title,
                        'description' => $assignment->description,
                        'course_id' => $assignment->course_id,
                        'course_code' => $subject?->subject_code ?? 'CODE-' . $assignment->course_id,
                        'course_name' => $subject?->subject_name ?? 'Untitled Course',
                        'instructor' => $assignment->course?->teacher?->user?->name ?? 'Unknown Instructor',
                        'due_date' => $dueDate?->format('Y-m-d'),
                        'due_time' => $assignment->due_time,
                        'due_datetime' => $dueDate?->toISOString(),
                        'points' => (float) ($assignment->points ?? 0),
                        'type' => $assignment->type ?? 'assignment',
                        'status' => $this->getAssignmentStatus($assignment, $submission),
                        'is_submitted' => $submission !== null,
                        'is_overdue' => $dueDate && $dueDate->isPast() && !$submission,
                        'days_until_due' => $dueDate ? max(0, $dueDate->diffInDays(now(), false) * -1) : null,
                        'submission' => $submission ? [
                            'id' => $submission->id,
                            'submitted_at' => $submission->submitted_at?->toISOString(),
                            'status' => $submission->status,
                            'score' => $submission->score,
                            'feedback' => $submission->feedback,
                            'file_url' => $submission->submission_file_path 
                                ? Storage::url($submission->submission_file_path) 
                                : null,
                        ] : null,
                        'created_at' => $assignment->created_at->toISOString(),
                    ];
                });

            return response()->json(['data' => $assignments], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@getAssignments error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load assignments'], 500);
        }
    }

    /**
     * Get pending assignments (not submitted, not overdue)
     * GET /api/student/assignments/pending
     */
    public function getPendingAssignments(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $assignments = Assignment::with(['course.majorSubject.subject'])
                ->whereIn('course_id', $courseIds)
                ->where('due_date', '>=', now())
                ->whereDoesntHave('submissions', function ($q) use ($student) {
                    $q->where('student_id', $student->id);
                })
                ->orderBy('due_date')
                ->get()
                ->map(function ($assignment) {
                    $subject = $assignment->course?->majorSubject?->subject;
                    $dueDate = Carbon::parse($assignment->due_date);

                    return [
                        'id' => $assignment->id,
                        'title' => $assignment->title,
                        'course_code' => $subject?->subject_code ?? 'CODE-' . $assignment->course_id,
                        'course_name' => $subject?->subject_name ?? 'Untitled Course',
                        'due_date' => $dueDate->format('Y-m-d'),
                        'due_time' => $assignment->due_time,
                        'points' => (float) ($assignment->points ?? 0),
                        'days_until_due' => max(0, $dueDate->diffInDays(now(), false) * -1),
                        'urgency' => $this->getUrgencyLevel($dueDate),
                    ];
                });

            return response()->json(['data' => $assignments], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@getPendingAssignments error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load pending assignments'], 500);
        }
    }

    /**
     * Get single assignment details
     * GET /api/student/assignments/{assignmentId}
     */
    public function getAssignment(Request $request, $assignmentId)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $assignment = Assignment::with([
                    'course.majorSubject.subject',
                    'course.teacher.user',
                    'submissions' => function ($q) use ($student) {
                        $q->where('student_id', $student->id);
                    }
                ])
                ->findOrFail($assignmentId);

            // Verify student is enrolled in this course
            $isEnrolled = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $assignment->course_id)
                ->where('status', 'enrolled')
                ->exists();

            if (!$isEnrolled) {
                return response()->json(['message' => 'Not enrolled in this course'], 403);
            }

            $subject = $assignment->course?->majorSubject?->subject;
            $submission = $assignment->submissions->first();
            $dueDate = $assignment->due_date ? Carbon::parse($assignment->due_date) : null;

            return response()->json([
                'data' => [
                    'id' => $assignment->id,
                    'title' => $assignment->title,
                    'description' => $assignment->description,
                    'instructions' => $assignment->instructions ?? null,
                    'course_id' => $assignment->course_id,
                    'course_code' => $subject?->subject_code ?? 'CODE-' . $assignment->course_id,
                    'course_name' => $subject?->subject_name ?? 'Untitled Course',
                    'instructor' => $assignment->course?->teacher?->user?->name ?? 'Unknown Instructor',
                    'due_date' => $dueDate?->format('Y-m-d'),
                    'due_time' => $assignment->due_time,
                    'points' => (float) ($assignment->points ?? 0),
                    'type' => $assignment->type ?? 'assignment',
                    'status' => $this->getAssignmentStatus($assignment, $submission),
                    'is_submitted' => $submission !== null,
                    'is_overdue' => $dueDate && $dueDate->isPast() && !$submission,
                    'can_submit' => !$dueDate || !$dueDate->isPast() || ($assignment->allow_late ?? false),
                    'submission' => $submission ? [
                        'id' => $submission->id,
                        'submission_text' => $submission->submission_text,
                        'submitted_at' => $submission->submitted_at?->toISOString(),
                        'status' => $submission->status,
                        'score' => $submission->score,
                        'feedback' => $submission->feedback,
                        'file_url' => $submission->submission_file_path 
                            ? Storage::url($submission->submission_file_path) 
                            : null,
                    ] : null,
                    'attachments' => $assignment->attachments ?? [],
                    'created_at' => $assignment->created_at->toISOString(),
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@getAssignment error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load assignment'], 500);
        }
    }

    /**
     * Submit an assignment
     * POST /api/student/assignments/submit
     */
    public function submitAssignment(Request $request)
    {
        $request->validate([
            'assignment_id' => 'required|exists:assignments,id',
            'submission_text' => 'nullable|string',
            'file' => 'nullable|file|max:10240', // 10MB max
        ]);

        // Require at least one
        if (!$request->filled('submission_text') && !$request->hasFile('file')) {
            return response()->json(['message' => 'Please provide submission text or file'], 422);
        }

        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $assignment = Assignment::findOrFail($request->assignment_id);

            // Verify enrollment
            $isEnrolled = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $assignment->course_id)
                ->where('status', 'enrolled')
                ->exists();

            if (!$isEnrolled) {
                return response()->json(['message' => 'Not enrolled in this course'], 403);
            }

            // Check if past due
            $dueDate = $assignment->due_date ? Carbon::parse($assignment->due_date) : null;
            $isLate = $dueDate && $dueDate->isPast();

            if ($isLate && !($assignment->allow_late ?? true)) {
                return response()->json(['message' => 'Assignment deadline has passed'], 422);
            }

            // Handle file upload
            $filePath = null;
            if ($request->hasFile('file')) {
                $filePath = $request->file('file')->store('assignment_submissions/' . $student->id, 'public');
            }

            // Create or update submission
            $submission = AssignmentSubmission::updateOrCreate(
                [
                    'assignment_id' => $request->assignment_id,
                    'student_id' => $student->id,
                ],
                [
                    'submission_text' => $request->submission_text,
                    'submission_file_path' => $filePath ?? null,
                    'status' => $isLate ? 'submitted_late' : 'submitted',
                    'submitted_at' => now(),
                ]
            );

            return response()->json([
                'message' => $isLate ? 'Assignment submitted late' : 'Assignment submitted successfully',
                'data' => [
                    'submission_id' => $submission->id,
                    'status' => $submission->status,
                    'submitted_at' => $submission->submitted_at->toISOString(),
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@submitAssignment error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to submit assignment'], 500);
        }
    }

    /**
     * Get assignment summary/stats
     * GET /api/student/assignments/summary
     */
    public function getSummary(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $total = Assignment::whereIn('course_id', $courseIds)->count();
            
            $submitted = AssignmentSubmission::where('student_id', $student->id)
                ->whereHas('assignment', fn($q) => $q->whereIn('course_id', $courseIds))
                ->count();

            $pending = Assignment::whereIn('course_id', $courseIds)
                ->where('due_date', '>=', now())
                ->whereDoesntHave('submissions', fn($q) => $q->where('student_id', $student->id))
                ->count();

            $overdue = Assignment::whereIn('course_id', $courseIds)
                ->where('due_date', '<', now())
                ->whereDoesntHave('submissions', fn($q) => $q->where('student_id', $student->id))
                ->count();

            $graded = AssignmentSubmission::where('student_id', $student->id)
                ->whereNotNull('score')
                ->count();

            return response()->json([
                'data' => [
                    'total' => $total,
                    'submitted' => $submitted,
                    'pending' => $pending,
                    'overdue' => $overdue,
                    'graded' => $graded,
                    'completion_rate' => $total > 0 ? round(($submitted / $total) * 100, 1) : 0,
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@getSummary error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load assignment summary'], 500);
        }
    }

    /**
     * Get assignment status
     */
    private function getAssignmentStatus($assignment, $submission): string
    {
        if ($submission) {
            if ($submission->score !== null) return 'graded';
            return $submission->status ?? 'submitted';
        }
        
        $dueDate = $assignment->due_date ? Carbon::parse($assignment->due_date) : null;
        if ($dueDate && $dueDate->isPast()) return 'overdue';
        
        return 'pending';
    }

    /**
     * Get urgency level based on due date
     */
    private function getUrgencyLevel(Carbon $dueDate): string
    {
        $daysUntilDue = $dueDate->diffInDays(now(), false) * -1;
        
        if ($daysUntilDue <= 1) return 'urgent';
        if ($daysUntilDue <= 3) return 'high';
        if ($daysUntilDue <= 7) return 'medium';
        return 'low';
    }
}
