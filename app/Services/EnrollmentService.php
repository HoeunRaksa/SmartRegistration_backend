<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrollmentService
{
    /**
     * Automatically enroll a student into all courses relevant to their major, academic year, semester, and class group.
     */
    public function autoEnrollStudent(int $studentId, int $majorId, string $academicYear, int $semester, ?int $classGroupId = null): int
    {
        try {
            // Find all courses for this major/year/semester
            // Also matching the class group if provided. 
            // Some courses might be "Shared" (class_group_id is null), we should include them too.
            
            $courses = Course::where('academic_year', $academicYear)
                ->where('semester', $semester)
                ->whereHas('majorSubject', function ($q) use ($majorId) {
                    $q->where('major_id', $majorId);
                })
                ->where(function ($q) use ($classGroupId) {
                    if ($classGroupId) {
                        $q->where('class_group_id', $classGroupId)
                          ->orWhereNull('class_group_id');
                    } else {
                        $q->whereNull('class_group_id');
                    }
                })
                ->get();

            $enrollCount = 0;
            foreach ($courses as $course) {
                // Check if already enrolled
                $exists = CourseEnrollment::where('student_id', $studentId)
                    ->where('course_id', $course->id)
                    ->exists();

                if (!$exists) {
                    CourseEnrollment::create([
                        'student_id' => $studentId,
                        'course_id' => $course->id,
                        'status' => 'enrolled',
                        'progress' => 0,
                        'enrolled_at' => now(),
                    ]);
                    $enrollCount++;
                }
            }

            Log::info("Auto enrollment for student $studentId: $enrollCount courses enrolled.");
            return $enrollCount;

        } catch (\Throwable $e) {
            Log::error("Auto enrollment failed for student $studentId: " . $e->getMessage());
            return 0;
        }
    }
}
