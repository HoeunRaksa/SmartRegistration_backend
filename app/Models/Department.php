<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    // Table name (optional if follows Laravel naming conventions)
    protected $table = 'departments';

    // Primary key
    protected $primaryKey = 'id';

    // Auto-incrementing primary key
    public $incrementing = true;

    // Fields allowed for mass assignment
    protected $fillable = [
        'name',
        'code',
        'faculty',
        'title',
        'description',
        'contact_email',
        'phone_number',
        'image_path'
    ];

    /**
     * Relationships
     */

    public function students()
    {
        return $this->hasMany(Student::class);
    }

    public function registrations()
    {
        return $this->hasMany(Registration::class);
    }

    public function staffs()
    {
        return $this->hasMany(Staff::class);
    }

    public function majors()
    {
        return $this->hasMany(Major::class);
    }

    public function teachers()
    {
        return $this->hasMany(Teacher::class);
    }
}
