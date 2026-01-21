<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseEnrollment extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'course_id',
        'status',
        'progress',
        'enrolled_at',
        'dropped_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'dropped_at' => 'datetime',
        'progress' => 'decimal:2',
    ];


    public function course()
    {
        return $this->belongsTo(\App\Models\Course::class, 'course_id');
    }

    public function student()
    {
        return $this->belongsTo(\App\Models\Student::class, 'student_id');
    }
}
