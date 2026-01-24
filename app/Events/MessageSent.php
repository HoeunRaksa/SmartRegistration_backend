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

    public $message;  // Make it public so it's accessible
    private int $a;
    private int $b;

    public function __construct(Message $message)
    {
        $message->load('attachments');
        
        $this->message = $message;  // Store the message itself

        $x = (int) $message->s_id;
        $y = (int) $message->r_id;
        $this->a = min($x, $y);
        $this->b = max($x, $y);
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
        return [
            's_id' => $this->message->s_id,
            'r_id' => $this->message->r_id,
            'content' => $this->message->content,
            'created_at' => $this->message->created_at,
            'id' => $this->message->id,
            'attachments' => $this->message->attachments,
        ];
    }
}