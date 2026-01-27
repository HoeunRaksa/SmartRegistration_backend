<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $table = 'courses';

    protected $fillable = [
        'major_subject_id',
        'teacher_id',
        'semester',
        'academic_year',
        'class_group_id',
    ];

    protected $appends = ['display_name'];

    public function majorSubject()
    {
        return $this->belongsTo(MajorSubject::class, 'major_subject_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function classGroup()
    {
        return $this->belongsTo(ClassGroup::class, 'class_group_id');
    }

    public function getDisplayNameAttribute(): string
    {
        $subjectName = $this->majorSubject?->subject?->subject_name ?? 'Untitled Course';
        $className   = $this->classGroup?->class_name ?? null;
        $year = $this->academic_year ?? null;
        $sem  = $this->semester ? 'Sem ' . $this->semester : null;

        $parts = [$subjectName];
        if ($className) $parts[] = $className;
        if ($year) $parts[] = $year;
        if ($sem) $parts[] = $sem;

        return implode(' — ', $parts);
    }

    public function classSchedules()
    {
        return $this->hasMany(ClassSchedule::class, 'course_id');
    }

    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class, 'course_id');
    }

    public function assignments()
    {
        return $this->hasMany(Assignment::class, 'course_id');
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'course_id');
    }
}