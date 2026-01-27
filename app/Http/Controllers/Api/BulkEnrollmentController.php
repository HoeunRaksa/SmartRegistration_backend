<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class BulkEnrollmentController extends Controller
{
    /**
     * Bulk enroll students from CSV/Excel
     * POST /api/admin/bulk-enrollment
     */
    public function bulkEnroll(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:csv,xlsx,xls',
                'course_id' => 'required|exists:courses,id',
            ]);

            $file = $request->file('file');
            $courseId = $request->course_id;
            
            // Read file content
            $data = \Maatwebsite\Excel\Facades\Excel::toArray([], $file)[0];
            
            // Skip header row
            array_shift($data);
            
            $success = [];
            $errors = [];
            
            DB::beginTransaction();
            
            foreach ($data as $index => $row) {
                try {
                    // Expected columns: email, student_code, full_name, etc.
                    $rowNum = $index + 2; // +2 because we skipped header and arrays are 0-indexed
                    
                    if (empty($row[0])) {
                        $errors[] = "Row {$rowNum}: Email is required";
                        continue;
                    }
                    
                    $email = trim($row[0]);
                    $studentCode = trim($row[1] ?? '');
                    
                    // Find student by email or student code
                    $student = null;
                    if ($studentCode) {
                        $student = Student::where('student_code', $studentCode)->first();
                    }
                    if (!$student && $email) {
                        $user = User::where('email', $email)->first();
                        if ($user) {
                            $student = Student::where('user_id', $user->id)->first();
                        }
                    }
                    
                    if (!$student) {
                        $errors[] = "Row {$rowNum}: Student not found (Email: {$email})";
                        continue;
                    }
                    
                    // Check if already enrolled
                    $existing = CourseEnrollment::where('student_id', $student->id)
                        ->where('course_id', $courseId)
                        ->first();
                        
                    if ($existing) {
                        $errors[] = "Row {$rowNum}: Student already enrolled (Code: {$student->student_code})";
                        continue;
                    }
                    
                    // Create enrollment
                    CourseEnrollment::create([
                        'student_id' => $student->id,
                        'course_id' => $courseId,
                        'status' => 'enrolled',
                        'enrolled_at' => now(),
                    ]);
                    
                    $success[] = "Row {$rowNum}: Enrolled {$student->student_code}";
                    
                } catch (\Exception $e) {
                    $errors[] = "Row {$rowNum}: " . $e->getMessage();
                }
            }
            
            if (!empty($errors)) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Bulk enrollment failed. Please fix errors and try again.',
                    'errors' => $errors,
                    'success_count' => 0,
                ], 422);
            }
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Bulk enrollment completed successfully',
                'success_count' => count($success),
                'details' => $success,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Bulk enrollment failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download enrollment template
     * GET /api/admin/bulk-enrollment/template
     */
    public function downloadTemplate()
    {
        try {
            $headers = ['email', 'student_code', 'full_name'];
            $exampleRow = ['student@example.com', 'STU2024001', 'Example Student'];
            
            $content = implode(',', $headers) . "\n" . implode(',', $exampleRow);
            
            return response($content)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', 'attachment; filename="bulk_enrollment_template.csv"');
                
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download template'
            ], 500);
        }
    }
}
