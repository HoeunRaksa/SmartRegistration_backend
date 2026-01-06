<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use HasFactory;

    protected $table = 'staffs';
    protected $primaryKey = 'id';

    protected $fillable = [
        'user_id',
        'user_name',
        'department_id',
        'department_name',
        'full_name',
        'full_name_kh',
        'position',
        'email',
        'phone_number',
        'address',
        'gender',
        'date_of_birth',
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
}
