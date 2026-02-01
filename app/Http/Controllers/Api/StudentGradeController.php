<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Grade;
use App\Models\Student;
use App\Models\CourseEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StudentGradeController extends Controller
{
    /**
     * Get all grades with detailed course info
     * GET /api/student/grades
     */
    public function getGrades(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $grades = Grade::with(['course.majorSubject.subject', 'course.teacher.user'])
                ->where('student_id', $student->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($grade) {
                    $course = $grade->course;
                    $subject = $course?->majorSubject?->subject;

                    return [
                        'id' => $grade->id,
                        'course_id' => $course?->id,
                        'course_code' => $subject?->subject_code ?? 'CODE-' . $course?->id,
                        'course_name' => $subject?->subject_name ?? 'Untitled Course',
                        'credits' => $subject?->credits ?? 0,
                        'instructor' => $course?->teacher?->user?->name ?? 'Unknown Instructor',
                        'semester' => $course?->semester,
                        'academic_year' => $course?->academic_year,
                        'assignment_name' => $grade->assignment_name,
                        'score' => (float) $grade->score,
                        'total_points' => (float) $grade->total_points,
                        'percentage' => $grade->total_points > 0 
                            ? round(($grade->score / $grade->total_points) * 100, 1) 
                            : 0,
                        'letter_grade' => $grade->letter_grade ?? $this->calculateLetterGrade($grade->grade_point),
                        'grade_point' => $grade->grade_point,
                        'graded_at' => $grade->created_at->toISOString(),
                    ];
                });

            return response()->json(['data' => $grades], 200);
        } catch (\Throwable $e) {
            Log::error('StudentGradeController@getGrades error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load grades'], 500);
        }
    }

    /**
     * Get grades by course
     * GET /api/student/grades/course/{courseId}
     */
    public function getGradesByCourse(Request $request, $courseId)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            $grades = Grade::with(['course.majorSubject.subject'])
                ->where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($grade) {
                    return [
                        'id' => $grade->id,
                        'assignment_name' => $grade->assignment_name,
                        'score' => (float) $grade->score,
                        'total_points' => (float) $grade->total_points,
                        'percentage' => $grade->total_points > 0 
                            ? round(($grade->score / $grade->total_points) * 100, 1) 
                            : 0,
                        'letter_grade' => $grade->letter_grade,
                        'grade_point' => $grade->grade_point,
                        'feedback' => $grade->feedback ?? null,
                        'graded_at' => $grade->created_at->toISOString(),
                    ];
                });

            return response()->json(['data' => $grades], 200);
        } catch (\Throwable $e) {
            Log::error('StudentGradeController@getGradesByCourse error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load course grades'], 500);
        }
    }

    /**
     * Get GPA and academic summary
     * GET /api/student/grades/gpa
     */
    public function getGpa(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();

            // Get all grades with grade points
            $grades = Grade::where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->with('course.majorSubject.subject')
                ->get();

            if ($grades->count() === 0) {
                return response()->json([
                    'data' => [
                        'gpa' => 0,
                        'cumulative_gpa' => 0,
                        'total_credits' => 0,
                        'credits_earned' => 0,
                        'credits_attempted' => 0,
                        'total_courses' => 0,
                        'grade_distribution' => [],
                    ]
                ], 200);
            }

            // Calculate weighted GPA (by credits)
            $totalWeightedPoints = 0;
            $totalCredits = 0;
            $creditsEarned = 0;
            $gradeDistribution = [];

            foreach ($grades as $grade) {
                $credits = $grade->course?->majorSubject?->subject?->credits ?? 3;
                $totalWeightedPoints += ($grade->grade_point * $credits);
                $totalCredits += $credits;

                if ($grade->grade_point >= 1.0) {
                    $creditsEarned += $credits;
                }

                // Grade distribution
                $letter = $grade->letter_grade ?? $this->calculateLetterGrade($grade->grade_point);
                $gradeDistribution[$letter] = ($gradeDistribution[$letter] ?? 0) + 1;
            }

            $gpa = $totalCredits > 0 ? round($totalWeightedPoints / $totalCredits, 2) : 0;

            // Get enrolled course count
            $enrolledCourses = CourseEnrollment::where('student_id', $student->id)
                ->where('status', 'enrolled')
                ->count();

            return response()->json([
                'data' => [
                    'gpa' => $gpa,
                    'cumulative_gpa' => $gpa,
                    'total_credits' => $totalCredits,
                    'credits_earned' => $creditsEarned,
                    'credits_attempted' => $totalCredits,
                    'total_courses_graded' => $grades->count(),
                    'current_enrolled' => $enrolledCourses,
                    'grade_distribution' => $gradeDistribution,
                    'academic_standing' => $this->getAcademicStanding($gpa),
                ]
            ], 200);
        } catch (\Throwable $e) {
            Log::error('StudentGradeController@getGpa error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to calculate GPA'], 500);
        }
    }

    /**
     * Get transcript summary (semester-wise)
     * GET /api/student/grades/transcript
     */
    public function getTranscript(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)
                ->with(['major.department'])
                ->firstOrFail();

            $grades = Grade::with(['course.majorSubject.subject'])
                ->where('student_id', $student->id)
                ->whereNotNull('grade_point')
                ->get();

            // Group by semester/academic year
            $groupedGrades = $grades->groupBy(function ($grade) {
                $course = $grade->course;
                return ($course->academic_year ?? 'Unknown') . ' - Semester ' . ($course->semester ?? '?');
            })->map(function ($semesterGrades, $period) {
                $totalWeighted = 0;
                $totalCredits = 0;

                $courses = $semesterGrades->map(function ($grade) use (&$totalWeighted, &$totalCredits) {
                    $subject = $grade->course?->majorSubject?->subject;
                    $credits = $subject?->credits ?? 3;
                    
                    $totalWeighted += ($grade->grade_point * $credits);
                    $totalCredits += $credits;

                    return [
                        'course_code' => $subject?->subject_code ?? 'CODE-' . ($grade->course_id ?? '?'),
                        'course_name' => $subject?->subject_name ?? 'Untitled Course',
                        'credits' => $credits,
                        'grade' => $grade->letter_grade ?? $this->calculateLetterGrade($grade->grade_point),
                        'grade_point' => $grade->grade_point,
                    ];
                });

                return [
                    'period' => $period,
                    'courses' => $courses->values(),
                    'semester_gpa' => $totalCredits > 0 ? round($totalWeighted / $totalCredits, 2) : 0,
                    'total_credits' => $totalCredits,
                ];
            })->values();

            // Total Stats
            $totalCredits = $groupedGrades->sum('total_credits');
            $totalWeighted = $groupedGrades->sum(fn($g) => $g['semester_gpa'] * $g['total_credits']);
            $cgpa = $totalCredits > 0 ? round($totalWeighted / $totalCredits, 2) : 0;

            $data = [
                'student' => $student,
                'transcript' => $groupedGrades,
                'cgpa' => $cgpa,
                'total_credits' => $totalCredits,
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];

            if ($request->download === 'pdf') {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.transcript', $data);
                return $pdf->download("transcript_{$student->student_code}.pdf");
            }

            return response()->json(['data' => $data], 200);
        } catch (\Throwable $e) {
            Log::error('StudentGradeController@getTranscript error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load transcript'], 500);
        }
    }

    /**
     * Calculate letter grade from grade point
     */
    private function calculateLetterGrade(?float $gradePoint): string
    {
        if ($gradePoint === null) return 'NG';
        if ($gradePoint >= 4.0) return 'A';
        if ($gradePoint >= 3.7) return 'A-';
        if ($gradePoint >= 3.3) return 'B+';
        if ($gradePoint >= 3.0) return 'B';
        if ($gradePoint >= 2.7) return 'B-';
        if ($gradePoint >= 2.3) return 'C+';
        if ($gradePoint >= 2.0) return 'C';
        if ($gradePoint >= 1.7) return 'C-';
        if ($gradePoint >= 1.3) return 'D+';
        if ($gradePoint >= 1.0) return 'D';
        return 'F';
    }

    /**
     * Get academic standing based on GPA
     */
    private function getAcademicStanding(float $gpa): string
    {
        if ($gpa >= 3.5) return 'Dean\'s List';
        if ($gpa >= 3.0) return 'Good Standing';
        if ($gpa >= 2.0) return 'Satisfactory';
        if ($gpa >= 1.5) return 'Academic Warning';
        return 'Academic Probation';
    }
}
