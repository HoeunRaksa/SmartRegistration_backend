<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $table = 'subjects';
    protected $primaryKey = 'id';

    protected $fillable = [
        'subject_name',
        'description',
        'credit'
    ];

    // ------------------- Relationships -------------------

    public function majorSubjects()
    {
        return $this->hasMany(MajorSubject::class);
    }
}
