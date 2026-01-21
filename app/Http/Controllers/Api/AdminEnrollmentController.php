<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseEnrollment;
use App\Models\Student;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminEnrollmentController extends Controller
{
    /**
     * GET /api/admin/enrollments
     */
    public function index(Request $request)
    {
        try {
            $enrollments = CourseEnrollment::with(['student', 'course'])
                ->orderByDesc('id')
                ->get()
                ->map(function ($e) {
                    return [
                        'id' => $e->id,
                        'student_id' => $e->student_id,
                        'course_id' => $e->course_id,
                        'status' => $e->status,
                        'created_at' => $e->created_at,
                        'updated_at' => $e->updated_at,

                        // helpful for admin UI
                        'student_name' => $e->student->full_name ?? $e->student->name ?? null,
                        'student_code' => $e->student->student_code ?? null,

                        'course_code' => $e->course->course_code ?? null,
                        'course_name' => $e->course->course_name ?? null,
                        'instructor'  => $e->course->instructor ?? ($e->course->teacher ?? null),
                    ];
                });

            return response()->json(['data' => $enrollments], 200);
        } catch (\Throwable $e) {
            Log::error('AdminEnrollmentController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load enrollments'], 500);
        }
    }

    /**
     * POST /api/admin/enrollments
     * Body: { student_id, course_id, status? }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'course_id'  => 'required|exists:courses,id',
            'status'     => 'nullable|string|in:enrolled,completed,dropped',
        ]);

        $status = $data['status'] ?? 'enrolled';

        $existing = CourseEnrollment::where('student_id', $data['student_id'])
            ->where('course_id', $data['course_id'])
            ->first();

        // If already enrolled, stop
        if ($existing && $existing->status === 'enrolled' && $status === 'enrolled') {
            return response()->json(['message' => 'Student already enrolled'], 409);
        }

        // Reuse record if exists, otherwise create
        if ($existing) {
            $update = ['status' => $status];

            if ($status === 'enrolled') {
                $update['progress'] = 0;
                $update['enrolled_at'] = now();
                $update['dropped_at'] = null;
            }

            if ($status === 'dropped') {
                $update['dropped_at'] = now();
            }

            $existing->update($update);

            return response()->json(['message' => 'Enrollment updated', 'data' => $existing], 200);
        }

        $enrollment = CourseEnrollment::create([
            'student_id'  => $data['student_id'],
            'course_id'   => $data['course_id'],
            'status'      => $status,
            'progress'    => $status === 'enrolled' ? 0 : null,
            'enrolled_at' => $status === 'enrolled' ? now() : null,
            'dropped_at'  => $status === 'dropped' ? now() : null,
        ]);

        return response()->json(['message' => 'Enrollment created', 'data' => $enrollment], 201);
    }


    /**
     * DELETE /api/admin/enrollments/{id}
     */
    public function destroy($id)
    {
        try {
            $enrollment = CourseEnrollment::findOrFail($id);
            $enrollment->delete();

            return response()->json(['message' => 'Enrollment deleted'], 200);
        } catch (\Throwable $e) {
            Log::error('AdminEnrollmentController@destroy error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to delete enrollment'], 500);
        }
    }

    /**
     * PUT /api/admin/enrollments/{id}/status
     * Body: { status }
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $data = $request->validate([
                'status' => 'required|string|in:enrolled,completed,dropped',
            ]);

            $enrollment = CourseEnrollment::findOrFail($id);
            $enrollment->status = $data['status'];
            $enrollment->save();

            return response()->json([
                'message' => 'Enrollment status updated',
                'data' => $enrollment->load(['student', 'course']),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('AdminEnrollmentController@updateStatus error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to update status'], 500);
        }
    }
}
