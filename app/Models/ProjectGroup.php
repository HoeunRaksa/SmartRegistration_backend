<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectGroup extends Model
{
    protected $fillable = ['course_id', 'teacher_id', 'name', 'max_capacity'];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function members()
    {
        return $this->hasMany(ProjectGroupMember::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'project_group_members');
    }
}
