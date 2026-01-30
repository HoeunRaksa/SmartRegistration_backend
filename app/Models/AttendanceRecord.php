<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_session_id',
        'student_id',
        'status',
        'notes',
        'remarks',
    ];

    public function classSession()
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
