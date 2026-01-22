<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_name',
        'major_id',
        'academic_year',
        'semester',
        'shift',
        'capacity',
    ];

    public function major()
    {
        return $this->belongsTo(\App\Models\Major::class, 'major_id');
    }

    public function courses()
    {
        return $this->hasMany(\App\Models\Course::class, 'class_group_id');
    }

    // ✅ NEW: class group -> students (by year/semester pivot)
    public function students()
    {
        return $this->belongsToMany(\App\Models\Student::class, 'student_class_groups')
            ->withPivot(['academic_year', 'semester'])
            ->withTimestamps();
    }
}
