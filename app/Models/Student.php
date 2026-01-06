<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $table = 'students';

    protected $fillable = [
        'student_code',
        'registration_id',
        'user_id',
        'department_id',
        'full_name_kh',
        'full_name_en',
        'date_of_birth',
        'gender',
        'nationality',
        'phone_number',
        'address',
        'generation',
        'parent_name',
        'parent_phone',
    ];

    protected static function boot()
    {
        parent::boot();

        // Auto-generate student code like STU-20250101010203-1234
        static::creating(function ($student) {
            if (empty($student->student_code)) {
                $student->student_code = 'STU-' . now()->format('YmdHis') . '-' . rand(1000, 9999);
            }
        });
    }

    // ------------------- Relationships -------------------

    public function registration()
    {
        return $this->belongsTo(Registration::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
