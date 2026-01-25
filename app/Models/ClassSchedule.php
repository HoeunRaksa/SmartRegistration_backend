<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'course_id',
        'day_of_week',
        'start_time',
        'end_time',
        'room',        // legacy string column
        'room_id',     // FK to rooms.id
        'session_type',
    ];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    // FIX: rename relationship to avoid collision with the `room` attribute
    public function roomRef()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
