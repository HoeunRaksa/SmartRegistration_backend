<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Registration extends Model
{
    use HasFactory;



    protected $table = 'registrations';
    protected $appends = ['profile_picture_url'];
    public function getProfilePictureUrlAttribute()
    {
        return $this->user?->profile_picture_url;
    }

    protected $fillable = [
        // Personal Info
        'first_name',
        'last_name',
        'full_name_kh',
        'full_name_en',
        'gender',
        'date_of_birth',
        'address',
        'current_address',
        'phone_number',
        'personal_email',

        // Education Info
        'high_school_name',
        'graduation_year',
        'grade12_result',

        // Study Info
        'department_id',
        'major_id',
        'faculty',
        'shift',
        'batch',
        'academic_year',
        'profile_picture_path',

        // Guardian Info
        'father_name',
        'fathers_date_of_birth',
        'fathers_nationality',
        'fathers_job',
        'fathers_phone_number',
        'mother_name',
        'mother_date_of_birth',
        'mother_nationality',
        'mothers_job',
        'mother_phone_number',
        'guardian_name',
        'guardian_phone_number',
        'emergency_contact_name',
        'emergency_contact_phone_number',

        // ✅ Payment
        'payment_status',
        'payment_tran_id',
        'payment_amount',
        'payment_date',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'payment_amount' => 'decimal:2',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'personal_email', 'email');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function major()
    {
        return $this->belongsTo(Major::class);
    }

    public function student()
    {
        return $this->hasOne(Student::class);
    }
}
