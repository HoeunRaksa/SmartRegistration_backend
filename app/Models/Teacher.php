<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    use HasFactory;

    protected $table = 'teachers';
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'department_id',
        'full_name',
        'full_name_kh',
        'gender',
        'date_of_birth',
        'address',
        'phone_number'
    ];

    // ------------------- Relationships -------------------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function courses()
    {
        return $this->hasMany(Course::class);
    }
}
