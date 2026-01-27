<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{a}.{b}', function ($user, $a, $b) {
    return (int)$user->id === (int)$a || (int)$user->id === (int)$b;
});

Broadcast::channel('conversation.{id}', function ($user, $id) {
    return \App\Models\ConversationParticipant::where('conversation_id', $id)
        ->where('user_id', $user->id)
        ->exists();
});