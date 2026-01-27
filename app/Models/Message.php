<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = ['conversation_id', 's_id', 'r_id', 'content', 'is_read', 'is_deleted', 'deleted_at'];

    protected $casts = [
        'is_deleted' => 'boolean',
        'is_read' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

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