<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class StudentMessageController extends Controller
{
    public function getConversations(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $conversations = Conversation::whereHas('participants', function ($q) use ($userId) {
                    $q->where('users.id', $userId);
                })
                ->withCount('messages')
                ->orderByDesc('updated_at')
                ->get();

            return response()->json(['data' => $conversations], 200);
        } catch (\Throwable $e) {
            Log::error('StudentMessageController@getConversations error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load conversations'], 500);
        }
    }

    public function getMessages(Request $request, $conversationId)
    {
        try {
            $userId = $request->user()->id;

            $conversation = Conversation::where('id', $conversationId)
                ->whereHas('participants', function ($q) use ($userId) {
                    $q->where('users.id', $userId);
                })
                ->firstOrFail();

            $messages = Message::with('sender')
                ->where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->get();

            return response()->json(['data' => $messages], 200);
        } catch (\Throwable $e) {
            Log::error('StudentMessageController@getMessages error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load messages'], 500);
        }
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'message' => 'required|string',
        ]);

        DB::beginTransaction();

        try {
            $userId = $request->user()->id;

            $conversation = Conversation::where('id', $request->conversation_id)
                ->whereHas('participants', function ($q) use ($userId) {
                    $q->where('users.id', $userId);
                })
                ->firstOrFail();

            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $userId,
                'message' => $request->message,
                'is_read' => false,
            ]);

            $conversation->touch();

            DB::commit();
            return response()->json(['message' => 'Sent', 'data' => $msg], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('StudentMessageController@sendMessage error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send message'], 500);
        }
    }
}
