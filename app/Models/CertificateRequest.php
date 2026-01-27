<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateRequest extends Model
{
    protected $fillable = [
        'student_id',
        'type',
        'status',
        'remarks',
        'processed_at',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
