<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public array $payload;
    private int $a;
    private int $b;

    public function __construct(Message $message)
    {
        $message->load('attachments');

        $x = (int) $message->s_id;
        $y = (int) $message->r_id;

        $this->a = min($x, $y);
        $this->b = max($x, $y);

        // ✅ SAFE, SERIALIZABLE PAYLOAD
        $this->payload = [
            'id'          => $message->id,
            's_id'        => $message->s_id,
            'r_id'        => $message->r_id,
            'content'     => $message->content,
            'created_at'  => $message->created_at,
            'attachments' => $message->attachments->toArray(),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("chat.{$this->a}.{$this->b}");
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