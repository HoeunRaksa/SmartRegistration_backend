<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectGroupMember extends Model
{
    protected $fillable = ['project_group_id', 'student_id'];

    public function group()
    {
        return $this->belongsTo(ProjectGroup::class, 'project_group_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
