<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\CourseEnrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentAssignmentController extends Controller
{
    public function getAssignments(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $courseIds = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->pluck('course_id');

            $assignments = Assignment::with(['course', 'submissions' => function ($q) use ($student) {
                    $q->where('student_id', $student->id);
                }])
                ->whereIn('course_id', $courseIds)
                ->orderBy('due_date')
                ->get();

            return response()->json(['data' => $assignments], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@getAssignments error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load assignments'], 500);
        }
    }

    public function submitAssignment(Request $request)
    {
        $request->validate([
            'assignment_id' => 'required|exists:assignments,id',
            'submission_text' => 'nullable|string',
            'file' => 'nullable|file|max:10240',
        ]);

        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $filePath = null;
            if ($request->hasFile('file')) {
                $filePath = $request->file('file')->store('assignment_submissions', 'public');
            }

            $submission = AssignmentSubmission::updateOrCreate(
                [
                    'assignment_id' => $request->assignment_id,
                    'student_id' => $student->id,
                ],
                [
                    'submission_text' => $request->submission_text,
                    'submission_file_path' => $filePath,
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]
            );

            return response()->json(['message' => 'Submitted successfully', 'data' => $submission], 200);
        } catch (\Throwable $e) {
            Log::error('StudentAssignmentController@submitAssignment error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to submit assignment'], 500);
        }
    }
}
