<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentClassGroup extends Model
{
    use HasFactory;

    protected $table = 'student_class_groups';

    protected $fillable = [
        'student_id',
        'class_group_id',
        'academic_year',
        'semester',
    ];

    protected $casts = [
        'semester' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }

    public function classGroup()
    {
        return $this->belongsTo(\App\Models\ClassGroup::class, 'class_group_id');
    }
}
