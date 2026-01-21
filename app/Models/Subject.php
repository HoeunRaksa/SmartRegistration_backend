<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;
protected static function booted()
{
    static::saving(function ($subject) {
        // if someone sends empty string, treat as null
        if ($subject->code !== null) {
            $subject->code = trim((string)$subject->code);
            if ($subject->code === '') {
                $subject->code = null;
            }
        }

        // auto generate only if still empty
        if (!$subject->code && $subject->id) {
            $subject->code = 'SUB-' . str_pad((string)$subject->id, 4, '0', STR_PAD_LEFT);
        }
    });

    static::created(function ($subject) {
        // When creating, ID exists only AFTER insert
        if (!$subject->code) {
            $subject->code = 'SUB-' . str_pad((string)$subject->id, 4, '0', STR_PAD_LEFT);
            $subject->saveQuietly();
        }
    });
}

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
