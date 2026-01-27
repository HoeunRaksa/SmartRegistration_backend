<?php

namespace App\Exports;

use App\Models\Grade;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class GradesExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $courseId;

    public function __construct($courseId)
    {
        $this->courseId = $courseId;
    }

    public function collection()
    {
        return Grade::with(['student.user', 'course.majorSubject.subject'])
            ->where('course_id', $this->courseId)
            ->get();
    }

    public function headings(): array
    {
        return [
            'Student Code',
            'Student Name',
            'Course',
            'Assignment',
            'Score',
            'Total Points',
            'Percentage',
            'Grade Point',
            'Graded At',
        ];
    }

    public function map($grade): array
    {
        $percentage = $grade->total_points > 0 
            ? ($grade->score / $grade->total_points) * 100 
            : 0;

        return [
            $grade->student->student_code ?? '',
            $grade->student->full_name_en ?? $grade->student->full_name ?? '',
            $grade->course->majorSubject->subject->subject_name ?? '',
            $grade->assignment_name ?? 'Final Grade',
            $grade->score ?? 0,
            $grade->total_points ?? 0,
            round($percentage, 2) . '%',
            $grade->grade_point ?? 0,
            $grade->created_at ? $grade->created_at->format('Y-m-d H:i') : '',
        ];
    }
}
