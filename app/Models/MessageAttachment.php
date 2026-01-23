<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageAttachment extends Model
{
    protected $fillable = ['message_id', 'type', 'file_path', 'original_name', 'file_size'];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}