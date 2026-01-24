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
    $path = null;

    // 1) student image
    if (!empty($this->profile_picture_path)) {
        $path = $this->profile_picture_path;
    }
    // 2) fallback to user image (works even if relation not loaded)
    elseif (!empty($this->user_id)) {
        $userPath = $this->relationLoaded('user')
            ? ($this->user?->profile_picture_path)
            : optional(\App\Models\User::find($this->user_id))->profile_picture_path;

        if (!empty($userPath)) $path = $userPath;
    }

    if (empty($path)) return null;

    // If already full URL
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    // Normalize: remove leading slash
    $path = ltrim($path, '/');

    // If it already starts with uploads/profiles/
    if (str_starts_with($path, 'uploads/profiles/')) {
        return asset($path);
    }

    // If stored as filename only
    return asset('uploads/profiles/' . basename($path));
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
