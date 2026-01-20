<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MajorSubject extends Model
{
    use HasFactory;

    protected $table = 'major_subjects';
    protected $primaryKey = 'id';

    protected $fillable = [
        'major_id',
        'subject_id',
        'year_level',
        'semester',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'year_level' => 'integer',
    ];

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }
}
