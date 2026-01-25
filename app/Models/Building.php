<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Building extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_code',
        'building_name',
        'description',
        'location',
        'total_floors',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'total_floors' => 'integer',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}