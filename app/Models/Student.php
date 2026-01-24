<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $table = 'students';
    protected $appends = ['profile_picture_url'];

  public function getProfilePictureUrlAttribute()
{
    // 1️⃣ student image (old style)
    if (!empty($this->profile_picture_path)) {
        return asset('uploads/profiles/' . basename($this->profile_picture_path));
    }

    // 2️⃣ user image (new style)
    if ($this->relationLoaded('user') && !empty($this->user?->profile_picture_path)) {
        return asset('uploads/profiles/' . basename($this->user->profile_picture_path));
    }

    // 3️⃣ no image
    return null;
}


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
        'profile_picture_path',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($student) {
            if (empty($student->student_code)) {
                $student->student_code = 'STU-' . now()->format('YmdHis') . '-' . rand(1000, 9999);
            }
        });
    }

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

    // ✅ NEW: student -> class groups (history by year/semester)
    public function classGroups()
    {
        return $this->belongsToMany(\App\Models\ClassGroup::class, 'student_class_groups')
            ->withPivot(['academic_year', 'semester'])
            ->withTimestamps();
    }

    // ✅ OPTIONAL helper: current class group for specific period
    public function classGroupFor(string $academicYear, int $semester)
    {
        return $this->classGroups()
            ->wherePivot('academic_year', $academicYear)
            ->wherePivot('semester', $semester)
            ->first();
    }
}
