<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['s_id', 'r_id', 'content', 'is_read'];

    public function sender()
    {
        return $this->belongsTo(User::class, 's_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'r_id');
    }

    public function attachments()
    {
        return $this->hasMany(MessageAttachment::class);
    }
}