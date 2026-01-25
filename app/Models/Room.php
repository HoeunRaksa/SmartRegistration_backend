<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'room_number',
        'room_name',
        'room_type',
        'capacity',
        'floor',
        'facilities',
        'is_available',
    ];

    protected $casts = [
        'facilities' => 'array',
        'is_available' => 'boolean',
        'capacity' => 'integer',
        'floor' => 'integer',
    ];

    public function building()
    {
        return $this->belongsTo(Building::class);
    }

    public function schedules()
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function sessions()
    {
        return $this->hasMany(ClassSession::class);
    }

    // Helper: Get full room identifier
    public function getFullNameAttribute()
    {
        return $this->building->building_code . '-' . $this->room_number;
    }
}