<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class StudentMessageController extends Controller
{
    /**
     * Get all conversations for the current student
     * GET /api/student/messages/conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $userId = $request->user()->id;

            // Get all unique user IDs the student has messaged with
            $sentTo = Message::where('s_id', $userId)->select('r_id as user_id')->distinct();
            $receivedFrom = Message::where('r_id', $userId)->select('s_id as user_id')->distinct();
            $conversationUserIds = $sentTo->union($receivedFrom)->pluck('user_id');

            $conversations = [];
            foreach ($conversationUserIds as $partnerId) {
                // Get latest message with this user
                $latestMessage = Message::where(function ($query) use ($userId, $partnerId) {
                    $query->where('s_id', $userId)->where('r_id', $partnerId);
                })->orWhere(function ($query) use ($userId, $partnerId) {
                    $query->where('s_id', $partnerId)->where('r_id', $userId);
                })
                ->with(['sender', 'receiver'])
                ->orderBy('created_at', 'DESC')
                ->first();

                if ($latestMessage) {
                    $participant = $latestMessage->s_id == $userId
                        ? $latestMessage->receiver
                        : $latestMessage->sender;

                    // Count unread messages from this partner
                    $unreadCount = Message::where('s_id', $partnerId)
                        ->where('r_id', $userId)
                        ->where('is_read', false)
                        ->count();

                    $conversations[] = [
                        'id' => $partnerId,
                        'participant' => [
                            'id' => $participant?->id,
                            'name' => $participant?->name ?? 'Unknown',
                            'email' => $participant?->email,
                        ],
                        'last_message' => $latestMessage->content,
                        'last_message_time' => $latestMessage->created_at->toISOString(),
                        'unread_count' => $unreadCount,
                    ];
                }
            }

            // Sort by last message time (descending)
            usort($conversations, function ($a, $b) {
                return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
            });

            return response()->json(['data' => $conversations], 200);
        } catch (\Throwable $e) {
            Log::error('StudentMessageController@getConversations error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load conversations'], 500);
        }
    }

    /**
     * Get messages with a specific user
     * GET /api/student/messages/{userId}
     */
    public function getMessages(Request $request, $partnerId)
    {
        try {
            $userId = $request->user()->id;

            // Get all messages between current user and partner
            $messages = Message::with(['sender', 'receiver', 'attachments'])
                ->where(function ($query) use ($userId, $partnerId) {
                    $query->where('s_id', $userId)->where('r_id', $partnerId);
                })
                ->orWhere(function ($query) use ($userId, $partnerId) {
                    $query->where('s_id', $partnerId)->where('r_id', $userId);
                })
                ->orderBy('created_at', 'ASC')
                ->get()
                ->map(function ($msg) use ($userId) {
                    return [
                        'id' => $msg->id,
                        'content' => $msg->content,
                        'sender_id' => $msg->s_id,
                        'receiver_id' => $msg->r_id,
                        'is_mine' => $msg->s_id == $userId,
                        'is_read' => $msg->is_read,
                        'created_at' => $msg->created_at->toISOString(),
                        'sender' => [
                            'id' => $msg->sender?->id,
                            'name' => $msg->sender?->name,
                        ],
                        'attachments' => $msg->attachments->map(fn($a) => [
                            'id' => $a->id,
                            'type' => $a->type,
                            'file_path' => $a->file_path,
                            'original_name' => $a->original_name,
                        ]),
                    ];
                });

            // Mark messages from partner as read
            Message::where('s_id', $partnerId)
                ->where('r_id', $userId)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            return response()->json(['data' => $messages], 200);
        } catch (\Throwable $e) {
            Log::error('StudentMessageController@getMessages error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load messages'], 500);
        }
    }

    /**
     * Send a message to another user
     * POST /api/student/messages/send
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'content' => 'nullable|string',
            'files.*' => 'nullable|file|max:10240',
        ]);

        // Require either content or files
        if (!$request->filled('content') && !$request->hasFile('files')) {
            return response()->json(['message' => 'Message content or files required'], 422);
        }

        DB::beginTransaction();

        try {
            $userId = $request->user()->id;

            $message = Message::create([
                's_id' => $userId,
                'r_id' => (int) $request->receiver_id,
                'content' => $request->content,
                'is_read' => false,
            ]);

            // Handle file attachments
            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $mime = $file->getMimeType();
                    $type = str_starts_with($mime, 'image/') ? 'image' : 
                           (str_starts_with($mime, 'audio/') ? 'audio' : 'file');

                    $path = $file->store("messages/{$message->id}", 'public');

                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'type' => $type,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $message->load(['attachments', 'receiver']);

            DB::commit();

            // Broadcast event if available
            try {
                if (class_exists(\App\Events\MessageSent::class)) {
                    broadcast(new \App\Events\MessageSent($message));
                }
            } catch (\Exception $e) {
                Log::warning('Could not broadcast message: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Message sent successfully',
                'data' => [
                    'id' => $message->id,
                    'content' => $message->content,
                    'receiver_id' => $message->r_id,
                    'created_at' => $message->created_at->toISOString(),
                ]
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StudentMessageController@sendMessage error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send message'], 500);
        }
    }

    /**
     * Get unread message count
     * GET /api/student/messages/unread-count
     */
    public function getUnreadCount(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $count = Message::where('r_id', $userId)
                ->where('is_read', false)
                ->count();

            return response()->json(['data' => ['unread_count' => $count]], 200);
        } catch (\Throwable $e) {
            Log::error('StudentMessageController@getUnreadCount error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to get unread count'], 500);
        }
    }
}
