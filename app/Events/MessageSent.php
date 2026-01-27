<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public array $payload;
    private $conversationId;

    public function __construct(Message $message)
    {
        $message->load('attachments');

        $this->conversationId = $message->conversation_id;

        // ✅ SAFE, SERIALIZABLE PAYLOAD
        $this->payload = [
            'id'              => $message->id,
            'conversation_id' => $message->conversation_id,
            's_id'            => $message->s_id,
            'r_id'            => $message->r_id,
            'content'         => $message->content,
            'created_at'      => $message->created_at,
            'attachments'     => $message->attachments->toArray(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("conversation.{$this->conversationId}");
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}