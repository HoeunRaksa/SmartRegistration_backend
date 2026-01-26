<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\Grade;
use Illuminate\Support\Facades\Log;
/**
 * StudentGradeController
 */
class StudentGradeController extends Controller
{
    /**
     * Get all grades
     * GET /api/student/grades
     */
    public function getGrades(Request $request)
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

            $grades = Grade::where('student_id', $student->id)
                ->with(['course.majorSubject.subject', 'course.classGroup'])
                ->orderBy('created_at', 'DESC')
                ->get();

            $formattedGrades = $grades->map(function ($grade) {
                $subject = $grade->course?->majorSubject?->subject;

                return [
                    'id' => $grade->id,
                    'course' => [
                        'id' => $grade->course?->id,
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                        'title' => $subject?->subject_name ?? 'N/A',
                    ],
                    'assignment_name' => $grade->assignment_name,
                    'score' => (float) $grade->score,
                    'total_points' => (float) $grade->total_points,
                    'percentage' => $grade->total_points > 0 
                        ? round(($grade->score / $grade->total_points) * 100, 2)
                        : 0,
                    'letter_grade' => $grade->letter_grade,
                    'grade_point' => (float) $grade->grade_point,
                    'feedback' => $grade->feedback,
                    'created_at' => $grade->created_at->toISOString(),
                    'updated_at' => $grade->updated_at->toISOString(),
                ];
            });

            return response()->json([
                'data' => $formattedGrades
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching grades: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch grades',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get GPA
     * GET /api/student/grades/gpa
     */
    public function getGpa(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => ['gpa' => 0]
                ], 404);
            }

            $gpaData = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->selectRaw('AVG(grade_point) as average_gpa, COUNT(*) as total_grades')
                ->first();

            $gpa = $gpaData->average_gpa ?? 0;

            return response()->json([
                'data' => [
                    'gpa' => round((float) $gpa, 2),
                    'total_grades' => $gpaData->total_grades ?? 0,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error calculating GPA: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to calculate GPA',
                'data' => ['gpa' => 0]
            ], 500);
        }
    }

    /**
     * Get transcript (optional - for future use)
     * GET /api/student/grades/transcript
     */
    public function getTranscript(Request $request)
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

            // Get grades grouped by course
            $grades = Grade::where('student_id', $student->id)
                ->with(['course.majorSubject.subject'])
                ->get()
                ->groupBy('course_id');

            $transcript = [];
            $totalGradePoints = 0;
            $totalCredits = 0;

            foreach ($grades as $courseId => $courseGrades) {
                $course = $courseGrades->first()->course;
                $subject = $course?->majorSubject?->subject;
                $credits = $subject?->credits ?? 0;

                $avgGradePoint = $courseGrades->avg('grade_point');
                $totalGradePoints += $avgGradePoint * $credits;
                $totalCredits += $credits;

                $transcript[] = [
                    'course_code' => $subject?->subject_code ?? 'N/A',
                    'course_name' => $subject?->subject_name ?? 'N/A',
                    'credits' => $credits,
                    'grade_point' => round((float) $avgGradePoint, 2),
                    'semester' => $course->semester,
                    'academic_year' => $course->academic_year,
                ];
            }

            $overallGpa = $totalCredits > 0 ? $totalGradePoints / $totalCredits : 0;

            return response()->json([
                'data' => [
                    'student' => [
                        'name' => $user->name,
                        'student_code' => $student->student_code,
                    ],
                    'transcript' => $transcript,
                    'summary' => [
                        'total_credits' => $totalCredits,
                        'gpa' => round($overallGpa, 2),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching transcript: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch transcript',
            ], 500);
        }
    }
}