<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    use HasFactory;

    protected $table = 'courses';
    protected $primaryKey = 'id';

    protected $fillable = [
        'major_subject_id',
        'teacher_id',
        'semester',
        'academic_year',
        'class_group_id',
    ];

    public function majorSubject()
    {
        return $this->belongsTo(MajorSubject::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function classGroup()
    {
        return $this->belongsTo(\App\Models\ClassGroup::class, 'class_group_id');
    }
}
