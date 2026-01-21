<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MajorSubject extends Model
{
    use HasFactory;

    protected $table = 'major_subjects';

    protected $fillable = [
        'major_id',
        'subject_id',
        'year_level',
        'semester',
        'is_required',
    ];

    protected $casts = [
        'year_level' => 'integer',
        'is_required' => 'boolean',
    ];

    public function major()
    {
        return $this->belongsTo(Major::class, 'major_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function courses()
    {
        return $this->hasMany(Course::class, 'major_subject_id');
    }
}
