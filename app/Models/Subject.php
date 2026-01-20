<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $table = 'subjects';

    protected $fillable = [
        'subject_name',
        'description',
        'credit',
        'department_id', // ✅ add
    ];

    protected $casts = [
        'credit' => 'integer',
    ];

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function majorSubjects()
    {
        return $this->hasMany(MajorSubject::class, 'subject_id');
    }

    public function majors()
    {
        return $this->belongsToMany(Major::class, 'major_subjects', 'subject_id', 'major_id');
    }
}
