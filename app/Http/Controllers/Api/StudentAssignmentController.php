<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
class StudentAssignmentController extends Controller
{
    /**
     * Get all assignments
     * GET /api/student/assignments
     */
    public function getAssignments(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => []
                ], 404);
            }

            $enrolledCourseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $assignments = Assignment::whereIn('course_id', $enrolledCourseIds)
                ->with([
                    'course.majorSubject.subject',
                    'course.classGroup',
                    'submissions' => function ($query) use ($student) {
                        $query->where('student_id', $student->id);
                    }
                ])
                ->orderBy('due_date', 'ASC')
                ->get();

            $formattedAssignments = $assignments->map(function ($assignment) {
                $subject = $assignment->course?->majorSubject?->subject;
                $submission = $assignment->submissions->first();

                return [
                    'id' => $assignment->id,
                    'course' => [
                        'id' => $assignment->course?->id,
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                        'title' => $subject?->subject_name ?? 'N/A',
                    ],
                    'title' => $assignment->title,
                    'description' => $assignment->description,
                    'due_date' => $assignment->due_date ? $assignment->due_date->format('Y-m-d') : null,
                    'due_time' => $assignment->due_time,
                    'points' => (float) $assignment->points,
                    'attachment_path' => $assignment->attachment_path,
                    'attachment_url' => $assignment->attachment_path 
                        ? Storage::url($assignment->attachment_path)
                        : null,
                    'submissions' => $assignment->submissions->map(function ($sub) {
                        return [
                            'id' => $sub->id,
                            'submitted_at' => $sub->submitted_at?->toISOString(),
                            'score' => (float) ($sub->score ?? 0),
                            'status' => $sub->status,
                            'feedback' => $sub->feedback,
                            'submission_file_url' => $sub->submission_file_path
                                ? Storage::url($sub->submission_file_path)
                                : null,
                        ];
                    })->toArray(),
                    'is_submitted' => $submission !== null,
                    'is_graded' => $submission && $submission->score !== null,
                    'is_overdue' => $assignment->due_date && $assignment->due_date->isPast() && !$submission,
                ];
            });

            return response()->json([
                'data' => $formattedAssignments
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching assignments: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch assignments',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get single assignment details
     * GET /api/student/assignments/{assignmentId}
     */
    public function getAssignment(Request $request, $assignmentId)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                ], 404);
            }

            $assignment = Assignment::with([
                'course.majorSubject.subject',
                'course.teacher.user',
                'submissions' => function ($query) use ($student) {
                    $query->where('student_id', $student->id);
                }
            ])->find($assignmentId);

            if (!$assignment) {
                return response()->json([
                    'error' => true,
                    'message' => 'Assignment not found',
                ], 404);
            }

            // Check if student is enrolled in this course
            $isEnrolled = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $assignment->course_id)
                ->where('status', 'enrolled')
                ->exists();

            if (!$isEnrolled) {
                return response()->json([
                    'error' => true,
                    'message' => 'You are not enrolled in this course',
                ], 403);
            }

            $subject = $assignment->course?->majorSubject?->subject;
            $teacher = $assignment->course?->teacher;
            $submission = $assignment->submissions->first();

            $data = [
                'id' => $assignment->id,
                'course' => [
                    'id' => $assignment->course?->id,
                    'course_code' => $subject?->subject_code ?? 'N/A',
                    'course_name' => $subject?->subject_name ?? 'N/A',
                    'instructor' => $teacher?->user?->name ?? 'N/A',
                ],
                'title' => $assignment->title,
                'description' => $assignment->description,
                'due_date' => $assignment->due_date ? $assignment->due_date->format('Y-m-d') : null,
                'due_time' => $assignment->due_time,
                'points' => (float) $assignment->points,
                'attachment_path' => $assignment->attachment_path,
                'attachment_url' => $assignment->attachment_path 
                    ? Storage::url($assignment->attachment_path)
                    : null,
                'submission' => $submission ? [
                    'id' => $submission->id,
                    'submitted_at' => $submission->submitted_at?->toISOString(),
                    'score' => (float) ($submission->score ?? 0),
                    'status' => $submission->status,
                    'feedback' => $submission->feedback,
                    'submission_text' => $submission->submission_text,
                    'submission_file_url' => $submission->submission_file_path
                        ? Storage::url($submission->submission_file_path)
                        : null,
                ] : null,
                'is_submitted' => $submission !== null,
                'is_graded' => $submission && $submission->score !== null,
                'is_overdue' => $assignment->due_date && $assignment->due_date->isPast() && !$submission,
                'created_at' => $assignment->created_at->toISOString(),
            ];

            return response()->json([
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching assignment: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch assignment',
            ], 500);
        }
    }

    /**
     * Submit assignment
     * POST /api/student/assignments/submit
     */
    public function submitAssignment(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'assignment_id' => 'required|exists:assignments,id',
                'submission_text' => 'nullable|string',
                'submission_file' => 'nullable|file|mimes:pdf,doc,docx,txt,zip|max:10240', // 10MB max
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $assignmentId = $request->input('assignment_id');
            $assignment = Assignment::find($assignmentId);

            // Check if student is enrolled
            $isEnrolled = CourseEnrollment::where('student_id', $student->id)
                ->where('course_id', $assignment->course_id)
                ->where('status', 'enrolled')
                ->exists();

            if (!$isEnrolled) {
                return response()->json([
                    'error' => true,
                    'message' => 'You are not enrolled in this course',
                ], 403);
            }

            // Check if already submitted
            $existingSubmission = AssignmentSubmission::where('assignment_id', $assignmentId)
                ->where('student_id', $student->id)
                ->first();

            if ($existingSubmission) {
                return response()->json([
                    'error' => true,
                    'message' => 'Assignment already submitted. Contact instructor to resubmit.',
                ], 400);
            }

            // Check if overdue
            if ($assignment->due_date && $assignment->due_date->isPast()) {
                return response()->json([
                    'error' => true,
                    'message' => 'Assignment is overdue',
                ], 400);
            }

            $submissionFilePath = null;
            if ($request->hasFile('submission_file')) {
                $submissionFilePath = $request->file('submission_file')
                    ->store('submissions', 'public');
            }

            // Create submission
            $submission = AssignmentSubmission::create([
                'assignment_id' => $assignmentId,
                'student_id' => $student->id,
                'submission_text' => $request->input('submission_text'),
                'submission_file_path' => $submissionFilePath,
                'status' => 'submitted',
                'submitted_at' => Carbon::now(),
            ]);

            return response()->json([
                'message' => 'Assignment submitted successfully',
                'data' => [
                    'submission_id' => $submission->id,
                    'assignment_id' => $assignmentId,
                    'submitted_at' => $submission->submitted_at->toISOString(),
                    'status' => $submission->status,
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error submitting assignment: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to submit assignment',
            ], 500);
        }
    }
}