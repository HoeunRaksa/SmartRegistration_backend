<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ChatController extends Controller
{
    public function index(Request $request, $userId)
    {
        $me = $request->user()->id;

        return Message::with('attachments')
            ->where(function ($q) use ($me, $userId) {
                $q->where('s_id', $me)->where('r_id', $userId);
            })
            ->orWhere(function ($q) use ($me, $userId) {
                $q->where('s_id', $userId)->where('r_id', $me);
            })
            ->orderBy('id', 'asc')
            ->get();
    }

    public function store(Request $request, $userId)
    {
        try {
            $me = $request->user()->id;

            $request->validate([
                'content' => ['nullable', 'string'],
                'files.*' => ['nullable', 'file', 'max:20480'],
            ]);

            if (!$request->filled('content') && !$request->hasFile('files')) {
                return response()->json(['message' => 'content or files required'], 422);
            }

            $message = Message::create([
                's_id' => $me,
                'r_id' => (int)$userId,
                'content' => $request->input('content'),
                'is_read' => false,
            ]);

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $mime = $file->getMimeType();
                    $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : 'file');

                    $path = $file->store("chat/{$message->id}", 'public');

                    MessageAttachment::create([
                        'message_id' => $message->id,
                        'type' => $type,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }

            $message->load('attachments');

            Log::info('About to broadcast message', ['message_id' => $message->id]);
            broadcast(new \App\Events\MessageSent($message));
            Log::info('Broadcast completed');

            return response()->json($message, 201);
        } catch (\Exception $e) {
            Log::error('Message send error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to send message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function conversations(Request $request)
    {
        $me = (int) $request->user()->id;

        // 1) Load all users except me (you can filter roles here if you want)
        $allUsers = User::where('id', '!=', $me)
            ->select('id', 'name')   // add role fields if you have them
            ->orderBy('name')
            ->get();

        // 2) Get latest message per partner (from messages table)
        // partner_id => (content, created_at)
        $latest = DB::table('messages as m')
            ->selectRaw("
            CASE WHEN m.s_id = ? THEN m.r_id ELSE m.s_id END as partner_id,
            MAX(m.id) as last_message_id
        ", [$me])
            ->where(function ($q) use ($me) {
                $q->where('m.s_id', $me)->orWhere('m.r_id', $me);
            })
            ->groupBy('partner_id');

        $lastMessages = DB::table('messages as last')
            ->joinSub($latest, 't', function ($join) {
                $join->on('last.id', '=', 't.last_message_id');
            })
            ->select([
                't.partner_id',
                'last.content',
                'last.created_at',
            ])
            ->get()
            ->keyBy('partner_id');

        // 3) unread counts from partner -> me
        $unreadCounts = Message::where('r_id', $me)
            ->where('is_read', false)
            ->selectRaw('s_id as partner_id, COUNT(*) as cnt')
            ->groupBy('s_id')
            ->pluck('cnt', 'partner_id');

        // 4) Merge into one list for your UI
        $data = $allUsers->map(function ($u) use ($lastMessages, $unreadCounts) {
            $last = $lastMessages[$u->id] ?? null;

            return [
                'id' => (int) $u->id,
                'name' => $u->name,
                'role' => null,
                'course' => null,

                'last_message' => $last?->content,
                'last_message_time' => $last?->created_at,
                'unread_count' => (int) ($unreadCounts[$u->id] ?? 0),

                'avatar' => null,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }
}