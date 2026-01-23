<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use App\Models\User;

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

        // ✅ REALTIME PUSH
        broadcast(new \App\Events\MessageSent($message))->toOthers();

        return response()->json($message, 201);
    }

    public function conversations(Request $request)
    {
        $me = (int) $request->user()->id;

        // Get all messages involving me (latest first)
        $all = Message::where('s_id', $me)
            ->orWhere('r_id', $me)
            ->orderByDesc('id')
            ->get();

        // partner_id => last_message
        $latestByPartner = [];
        foreach ($all as $m) {
            $partnerId = ($m->s_id === $me) ? (int)$m->r_id : (int)$m->s_id;

            if (!isset($latestByPartner[$partnerId])) {
                $latestByPartner[$partnerId] = $m; // first one is latest because sorted desc
            }
        }

        $partnerIds = array_keys($latestByPartner);

        // unread counts (partner -> me)
        $unreadCounts = Message::where('r_id', $me)
            ->where('is_read', false)
            ->selectRaw('s_id as partner_id, COUNT(*) as cnt')
            ->groupBy('s_id')
            ->pluck('cnt', 'partner_id');

        $users = User::whereIn('id', $partnerIds)->get()->keyBy('id');

        $data = [];
        foreach ($partnerIds as $pid) {
            $last = $latestByPartner[$pid];
            $u = $users[$pid] ?? null;

            $data[] = [
                'id' => (int)$pid,                 // partner user id (your UI uses this)
                'name' => $u?->name ?? 'User',
                'role' => null,
                'course' => null,
                'last_message' => $last->content,
                'last_message_time' => $last->created_at,
                'unread_count' => (int)($unreadCounts[$pid] ?? 0),
                'avatar' => null,
            ];
        }

        return response()->json(['data' => $data]);
    }
}