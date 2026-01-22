<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassGroup extends Model
{
    protected $fillable = [
        'class_name',
        'major_id',
        'academic_year',
        'semester',
        'shift',
        'capacity'
    ];

    public function courses()
    {
        return $this->hasMany(\App\Models\Course::class, 'class_group_id');
    }

    public function major()
    {
        return $this->belongsTo(\App\Models\Major::class);
    }
}
