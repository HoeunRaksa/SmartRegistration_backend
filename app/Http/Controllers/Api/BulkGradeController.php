<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Grade;
use App\Models\Student;
use App\Models\Course;
use Illuminate\Support\Facades\DB;

class BulkGradeController extends Controller
{
    /**
     * Bulk import grades from Excel
     * POST /api/teacher/bulk-grades
     */
    public function bulkImport(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,xlsx,xls',
                'course_id' => 'required|exists:courses,id',
                'assignment_name' => 'required|string',
                'total_points' => 'required|numeric|min:0',
            ]);

            $file = $request->file('file');
            $courseId = $request->course_id;
            $assignmentName = $request->assignment_name;
            $totalPoints = $request->total_points;
            
            // Read file
            $data = \Maatwebsite\Excel\Facades\Excel::toArray([], $file)[0];
            array_shift($data); // Skip header
            
            $success = [];
            $errors = [];
            
            DB::beginTransaction();
            
            foreach ($data as $index => $row) {
                try {
                    $rowNum = $index + 2;
                    
                    if (empty($row[0])) {
                        $errors[] = "Row {$rowNum}: Student code/email is required";
                        continue;
                    }
                    
                    $identifier = trim($row[0]);
                    $score = floatval($row[1] ?? 0);
                    
                    // Find student
                    $student = Student::where('student_code', $identifier)->first();
                    if (!$student) {
                        $user = \App\Models\User::where('email', $identifier)->first();
                        if ($user) {
                            $student = Student::where('user_id', $user->id)->first();
                        }
                    }
                    
                    if (!$student) {
                        $errors[] = "Row {$rowNum}: Student not found ({$identifier})";
                        continue;
                    }
                    
                    // Validate score
                    if ($score < 0 || $score > $totalPoints) {
                        $errors[] = "Row {$rowNum}: Invalid score (must be 0-{$totalPoints})";
                        continue;
                    }
                    
                    // Create or update grade
                    Grade::updateOrCreate(
                        [
                            'student_id' => $student->id,
                            'course_id' => $courseId,
                            'assignment_name' => $assignmentName,
                        ],
                        [
                            'score' => $score,
                            'total_points' => $totalPoints,
                            'grade_point' => ($score / $totalPoints) * 4, // Convert to 4.0 scale
                        ]
                    );
                    
                    $success[] = "Row {$rowNum}: Graded {$student->student_code} - {$score}/{$totalPoints}";
                    
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }
            
            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Bulk grade import failed',
                    'errors' => $errors,
                ], 422);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Grades imported successfully',
                'success_count' => count($success),
                'details' => $success,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to import grades: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download grade import template
     * GET /api/teacher/bulk-grades/template
     */
    public function downloadTemplate()
    {
        $headers = ['student_code_or_email', 'score', 'comments'];
        $exampleRow = ['STU2024001', '85.5', 'Good work'];
        
        $content = implode(',', $headers) . "\n" . implode(',', $exampleRow);
        
        return response($content)
            ->header('Content-Type', 'text/csv')
            ->header('Content-Disposition', 'attachment; filename="bulk_grades_template.csv"');
    }
}
