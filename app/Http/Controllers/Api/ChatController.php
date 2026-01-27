<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ChatController extends Controller
{
    /**
     * Get or create a private conversation with another user.
     */
    protected function getPrivateConversation($userId, $currentUserId)
    {
        $userIds = [(int)$userId, (int)$currentUserId];
        sort($userIds);

        // Find a private conversation that has exactly these two participants
        return Conversation::where('type', 'private')
            ->whereHas('participants', function ($q) use ($currentUserId) {
                $q->where('user_id', $currentUserId);
            })
            ->whereHas('participants', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->where(function ($query) {
                $query->has('participants', '=', 2);
            })
            ->first();
    }

    /**
     * Get messages for a specific user ID (Backward compatible)
     */
    public function index(Request $request, $userId)
    {
        $me = $request->user()->id;
        $conv = $this->getPrivateConversation($userId, $me);

        if (!$conv) return response()->json([]);

        return Message::with('attachments', 'sender')
            ->where('conversation_id', $conv->id)
            ->where('is_deleted', false)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Get messages for a specific conversation ID (New)
     */
    public function getConversationMessages(Request $request, $conversationId)
    {
        $me = $request->user()->id;
        
        // Verify participant
        $isParticipant = ConversationParticipant::where('conversation_id', $conversationId)
            ->where('user_id', $me)
            ->exists();
            
        if (!$isParticipant) return response()->json(['message' => 'Unauthorized'], 403);

        return Message::with('attachments', 'sender')
            ->where('conversation_id', $conversationId)
            ->where('is_deleted', false)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Send message to a user (Backward compatible)
     */
    public function store(Request $request, $userId)
    {
        $me = $request->user()->id;
        $conv = $this->getPrivateConversation($userId, $me);

        if (!$conv) {
            $conv = Conversation::create(['type' => 'private']);
            ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $me]);
            ConversationParticipant::create(['conversation_id' => $conv->id, 'user_id' => $userId]);
        }

        return $this->sendMessageToConversation($request, $conv->id);
    }

    /**
     * Send message to a conversation ID (New)
     */
    public function sendMessage(Request $request, $conversationId)
    {
        return $this->sendMessageToConversation($request, $conversationId);
    }

    protected function sendMessageToConversation(Request $request, $conversationId)
    {
        try {
            $me = $request->user()->id;

            $request->validate([
                'content' => ['nullable', 'string'],
                'files.*' => ['nullable', 'file', 'max:20480'], // 20MB
            ]);

            if (!$request->filled('content') && !$request->hasFile('files')) {
                return response()->json(['message' => 'content or files required'], 422);
            }

            $message = Message::create([
                'conversation_id' => $conversationId,
                's_id' => $me,
                // r_id is kept for legacy but might be null for group chats
                'r_id' => ConversationParticipant::where('conversation_id', $conversationId)
                            ->where('user_id', '!=', $me)
                            ->first()?->user_id,
                'content' => $request->input('content'),
                'is_read' => false,
            ]);

            if ($request->hasFile('files')) {
                foreach ($request->file('files') as $file) {
                    $mime = $file->getMimeType();
                    $type = 'file';
                    if (str_starts_with($mime, 'image/')) $type = 'image';
                    elseif (str_starts_with($mime, 'audio/')) $type = 'audio';

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

            $message->load(['attachments', 'sender']);

            broadcast(new \App\Events\MessageSent($message))->toOthers();

            return response()->json($message, 201);
        } catch (\Exception $e) {
            Log::error('Message send error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send message', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a group conversation
     */
    public function createGroup(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $me = $request->user()->id;
        $userIds = array_unique(array_merge($request->user_ids, [$me]));

        return DB::transaction(function () use ($request, $me, $userIds) {
            $conv = Conversation::create([
                'title' => $request->title,
                'type' => 'group',
                'creator_id' => $me,
            ]);

            foreach ($userIds as $userId) {
                ConversationParticipant::create([
                    'conversation_id' => $conv->id,
                    'user_id' => $userId,
                ]);
            }

            return response()->json($conv->load('participants.user'), 201);
        });
    }

    /**
     * Add participants to a group
     */
    public function addParticipants(Request $request, $conversationId)
    {
        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
        ]);

        $conv = Conversation::findOrFail($conversationId);
        if ($conv->type !== 'group') return response()->json(['message' => 'Not a group chat'], 422);

        foreach ($request->user_ids as $userId) {
            ConversationParticipant::firstOrCreate([
                'conversation_id' => $conv->id,
                'user_id' => $userId,
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Remove participant from a group
     */
    public function removeParticipant(Request $request, $conversationId, $userId)
    {
        $conv = Conversation::findOrFail($conversationId);
        if ($conv->type !== 'group') return response()->json(['message' => 'Not a group chat'], 422);

        // Only creator or the user themselves can remove
        if ($conv->creator_id !== $request->user()->id && $request->user()->id !== (int)$userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        ConversationParticipant::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Delete a message (Soft Delete)
     */
    public function deleteMessage(Request $request, $messageId)
    {
        $message = Message::findOrFail($messageId);
        $user = $request->user();

        // Check if sender OR teacher/admin/staff participant
        $isSender = (int)$message->s_id === (int)$user->id;
        $isPrivileged = in_array($user->role, ['teacher', 'admin', 'staff']);
        
        // Verify user is actually a participant of this conversation
        $isParticipant = ConversationParticipant::where('conversation_id', $message->conversation_id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$isSender && !($isPrivileged && $isParticipant)) {
            return response()->json([
                'message' => 'Unauthorized',
                'debug' => [
                    'message_sender' => $message->s_id,
                    'current_user' => $user->id,
                    'is_sender' => $isSender,
                    'is_privileged' => $isPrivileged,
                    'is_participant' => $isParticipant
                ]
            ], 403);
        }

        $message->update([
            'is_deleted' => true,
            'deleted_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Clear all messages in a conversation (Soft Delete All)
     */
    public function clearConversation(Request $request, $id)
    {
        $me = $request->user()->id;

        // Verify participant
        $isParticipant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $me)
            ->exists();
            
        if (!$isParticipant) return response()->json(['message' => 'Unauthorized'], 403);

        Message::where('conversation_id', $id)
            ->update([
                'is_deleted' => true,
                'deleted_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }

    /**
     * List all conversations for the current user
     */
    public function conversations(Request $request)
    {
        $me = $request->user();
        $this->syncCourseGroups($me);

        $conversations = Conversation::with(['latestMessage', 'participants.user', 'course.majorSubject.subject'])
            ->whereHas('participants', function ($q) use ($me) {
                $q->where('user_id', $me->id);
            })
            ->get();

        $data = $conversations->map(function ($conv) use ($me) {
            $last = $conv->latestMessage;
            
            $name = $conv->title;
            $avatar = null;

            if ($conv->course_id) {
                $name = $conv->course->display_name ?? $conv->title;
            }

            $otherUserId = null;
            if ($conv->type === 'private') {
                $other = $conv->participants->where('user_id', '!=', $me->id)->first()?->user;
                $name = $other?->name ?? 'Deleted User';
                $avatar = $other?->profile_picture_url;
                $otherUserId = $other?->id;
            }

            return [
                'id' => $conv->id,
                'conversation_id' => $conv->id,
                'name' => $name,
                'type' => $conv->type,
                'course_id' => $conv->course_id,
                'other_user_id' => $otherUserId,
                'creator_id' => $conv->creator_id,
                'last_message' => $last?->is_deleted ? 'Message deleted' : $last?->content,
                'last_message_time' => $last?->created_at,
                'unread_count' => Message::where('conversation_id', $conv->id)
                                    ->where('r_id', $me->id)
                                    ->where('is_read', false)
                                    ->count(),
                'avatar' => $avatar,
                'participants_count' => $conv->participants->count(),
            ];
        })->sortByDesc('last_message_time')->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Sync course groups for the current user.
     */
    protected function syncCourseGroups($user)
    {
        $courses = [];
        if ($user->role === 'student') {
            $courses = \App\Models\CourseEnrollment::where('student_id', $user->student?->id)
                ->pluck('course_id');
        } elseif ($user->role === 'teacher') {
            $courses = \App\Models\Course::where('teacher_id', $user->teacher?->id)
                ->pluck('id');
        }

        foreach ($courses as $courseId) {
            $conv = Conversation::firstOrCreate([
                'course_id' => $courseId,
                'type' => 'course_group'
            ], [
                'title' => 'Course Group Chat',
            ]);

            ConversationParticipant::firstOrCreate([
                'conversation_id' => $conv->id,
                'user_id' => $user->id
            ]);
        }
    }

    /**
     * Get classmates/peers for the current student.
     */
    public function getClassmates(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'student') {
            return response()->json(['data' => []]);
        }

        $student = $user->student;
        if (!$student) return response()->json(['data' => []]);

        $myCourseIds = \App\Models\CourseEnrollment::where('student_id', $student->id)
            ->pluck('course_id');

        $classmates = User::whereHas('student.enrollments', function ($q) use ($myCourseIds) {
            $q->whereIn('course_id', $myCourseIds);
        })
        ->where('id', '!=', $user->id)
        ->distinct()
        ->get()
        ->map(function ($u) {
            return [
                'id' => $u->id,
                'name' => $u->name,
                'avatar' => $u->profile_picture_url,
                'role' => $u->role,
                'student_id' => $u->student?->student_code
            ];
        });

        return response()->json(['data' => $classmates]);
    }

    /**
     * Delete a conversation (if owner)
     */
    public function destroy(Request $request, $id)
    {
        $conv = Conversation::findOrFail($id);
        if ($conv->creator_id !== $request->user()->id && $request->user()->id !== 1) { // 1 for admin
             return response()->json(['message' => 'Unauthorized'], 403);
        }

        $conv->delete(); // Cascades to messages and participants
        return response()->json(['success' => true]);
    }
}