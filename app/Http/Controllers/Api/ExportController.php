<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Exports\StudentsExport;
use App\Exports\GradesExport;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    /**
     * Export students to Excel
     * GET /api/admin/export/students
     */
    public function exportStudents(Request $request)
    {
        try {
            $filters = $request->only(['department_id', 'generation']);
            
            return Excel::download(
                new StudentsExport($filters),
                'students_' . date('Y-m-d') . '.xlsx'
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export grades for a course
     * GET /api/teacher/export/grades/{courseId}
     */
    public function exportGrades($courseId)
    {
        try {
            return Excel::download(
                new GradesExport($courseId),
                'grades_course_' . $courseId . '_' . date('Y-m-d') . '.xlsx'
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export attendance summary
     * GET /api/teacher/export/attendance/{courseId}
     */
    public function exportAttendance($courseId)
    {
        try {
            // Get all students enrolled in the course
            $enrollments = \App\Models\CourseEnrollment::with(['student.user', 'course.majorSubject.subject'])
                ->where('course_id', $courseId)
                ->where('status', 'enrolled')
                ->get();

            $course = \App\Models\Course::with('majorSubject.subject')->find($courseId);
            $courseName = $course->majorSubject->subject->subject_name ?? 'Course';

            $data = [];
            $headers = ['Student Code', 'Student Name', 'Total Classes', 'Present', 'Absent', 'Attendance Rate'];

            foreach ($enrollments as $enrollment) {
                $student = $enrollment->student;
                
                $total = \App\Models\AttendanceRecord::where('student_id', $student->id)
                    ->whereHas('classSession', function($q) use ($courseId) {
                        $q->where('course_id', $courseId);
                    })
                    ->count();

                $present = \App\Models\AttendanceRecord::where('student_id', $student->id)
                    ->where('status', 'present')
                    ->whereHas('classSession', function($q) use ($courseId) {
                        $q->where('course_id', $courseId);
                    })
                    ->count();

                $absent = $total - $present;
                $rate = $total > 0 ? round(($present / $total) * 100, 2) : 0;

                $data[] = [
                    $student->student_code ?? '',
                    $student->full_name_en ?? $student->full_name ?? '',
                    $total,
                    $present,
                    $absent,
                    $rate . '%',
                ];
            }

            // Create CSV content
            $csvContent = implode(',', $headers) . "\n";
            foreach ($data as $row) {
                $csvContent .= implode(',', $row) . "\n";
            }

            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="attendance_' . $courseName . '_' . date('Y-m-d') . '.csv"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export payment/registration report
     * GET /api/admin/export/payments
     */
    public function exportPayments(Request $request)
    {
        try {
            $semester = $request->get('semester', 1);
            
            $registrations = \App\Models\Registration::with(['department', 'major'])
                ->get();

            $headers = ['ID', 'Student Name', 'Email', 'Department', 'Major', 'Academic Year', 'Semester', 'Payment Status', 'Amount', 'Paid At'];
            $data = [];

            foreach ($registrations as $reg) {
                // Get payment status for this semester
                $period = $reg->student_academic_periods()
                    ->where('semester', $semester)
                    ->first();

                $paymentStatus = $period->payment_status ?? 'PENDING';
                $amount = $period->tuition_amount ?? 0;
                $paidAt = $period->paid_at ?? '';

                $data[] = [
                    $reg->id,
                    $reg->full_name_en ?? '',
                    $reg->personal_email ?? '',
                    $reg->department_name ?? '',
                    $reg->major_name ?? '',
                    $reg->academic_year ?? '',
                    $semester,
                    $paymentStatus,
                    number_format($amount, 2),
                    $paidAt ? date('Y-m-d H:i', strtotime($paidAt)) : '',
                ];
            }

            $csvContent = implode(',', $headers) . "\n";
            foreach ($data as $row) {
                $csvContent .= implode(',', $row) . "\n";
            }

            return response($csvContent)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="payment_report_sem' . $semester . '_' . date('Y-m-d') . '.csv"');

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
