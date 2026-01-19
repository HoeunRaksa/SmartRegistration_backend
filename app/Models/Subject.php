<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'subjects';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'subject_name',
        'description',
        'credit',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string,  string>
     */
    protected $casts = [
        'credit' => 'integer',
    ];

    /**
     * Relationship: A subject can be linked to many majors through major_subjects
     */
    public function majorSubjects()
    {
        return $this->hasMany(MajorSubject::class, 'subject_id');
    }

    /**
     * Relationship: Many-to-Many with Major through major_subjects
     */
    public function majors()
    {
        return $this->belongsToMany(Major::class, 'major_subjects', 'subject_id', 'major_id');
    }
}