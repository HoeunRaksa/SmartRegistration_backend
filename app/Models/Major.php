<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Major extends Model
{
    use HasFactory;

    protected $table = 'majors';
    protected $primaryKey = 'id';

    protected $fillable = [
        'major_name',
        'description',
        'image',
        'department_id',
        'registration_fee'
    ];

    protected $casts = [
        'registration_fee' => 'decimal:2'
    ];


    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function majorSubjects()
    {
        return $this->hasMany(MajorSubject::class);
    }
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'major_subjects', 'major_id', 'subject_id')
            ->withTimestamps();
    }
}
