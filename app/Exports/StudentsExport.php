<?php

namespace App\Exports;

use App\Models\Student;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class StudentsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection()
    {
        $query = Student::with(['user', 'department']);

        // Apply filters
        if (!empty($this->filters['department_id'])) {
            $query->where('department_id', $this->filters['department_id']);
        }

        if (!empty($this->filters['generation'])) {
            $query->where('generation', $this->filters['generation']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Student Code',
            'Full Name (EN)',
            'Full Name (KH)',
            'Email',
            'Phone',
            'Gender',
            'Date of Birth',
            'Department',
            'Generation',
            'Registered At',
        ];
    }

    public function map($student): array
    {
        return [
            $student->student_code ?? '',
            $student->full_name_en ?? $student->full_name ?? '',
            $student->full_name_kh ?? '',
            $student->user->email ?? '',
            $student->phone_number ?? '',
            $student->gender ?? '',
            $student->date_of_birth ?? '',
            $student->department->name ?? '',
            $student->generation ?? '',
            $student->created_at ? $student->created_at->format('Y-m-d H:i') : '',
        ];
    }
}
