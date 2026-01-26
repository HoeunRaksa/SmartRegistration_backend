<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use App\Models\AttendanceRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
/**
 * StudentAttendanceController
 */
class StudentAttendanceController extends Controller
{
    /**
     * Get attendance records
     * GET /api/student/attendance
     */
    public function getAttendance(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => []
                ], 404);
            }

            $attendance = AttendanceRecord::where('student_id', $student->id)
                ->with(['classSession.course.majorSubject.subject'])
                ->orderBy('created_at', 'DESC')
                ->get();

            $formattedAttendance = $attendance->map(function ($record) {
                $session = $record->classSession;
                $course = $session?->course;
                $subject = $course?->majorSubject?->subject;

                return [
                    'id' => $record->id,
                    'course' => [
                        'course_code' => $subject?->subject_code ?? 'N/A',
                        'course_name' => $subject?->subject_name ?? 'N/A',
                    ],
                    'session_date' => $session?->session_date,
                    'session_time' => $session?->session_time,
                    'status' => $record->status,
                    'notes' => $record->notes,
                    'recorded_at' => $record->created_at->toISOString(),
                ];
            });

            return response()->json([
                'data' => $formattedAttendance
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching attendance: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch attendance records',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get attendance statistics
     * GET /api/student/attendance/stats
     */
    public function getStats(Request $request)
    {
        try {
            $user = $request->user();
            $student = Student::where('user_id', $user->id)->first();

            if (!$student) {
                return response()->json([
                    'error' => true,
                    'message' => 'Student profile not found',
                    'data' => ['total' => 0, 'present' => 0]
                ], 404);
            }

            $stats = AttendanceRecord::where('student_id', $student->id)
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = "late" THEN 1 ELSE 0 END) as late
                ')
                ->first();

            $total = $stats->total ?? 0;
            $present = $stats->present ?? 0;
            $absent = $stats->absent ?? 0;
            $late = $stats->late ?? 0;

            $percentage = $total > 0 ? ($present / $total) * 100 : 0;

            return response()->json([
                'data' => [
                    'total' => (int) $total,
                    'present' => (int) $present,
                    'absent' => (int) $absent,
                    'late' => (int) $late,
                    'percentage' => round($percentage, 2),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching attendance stats: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch attendance statistics',
                'data' => ['total' => 0, 'present' => 0]
            ], 500);
        }
    }
}

// ============================================================

/**
 * StudentMessageController
 */
class StudentMessageController extends Controller
{
    /**
     * Get all conversations
     * GET /api/student/messages/conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $user = $request->user();

            // Get unique conversation partners
            $sentTo = \App\Models\Message::where('s_id', $user->id)
                ->select('r_id as user_id')
                ->distinct();

            $receivedFrom = \App\Models\Message::where('r_id', $user->id)
                ->select('s_id as user_id')
                ->distinct();

            $conversationUserIds = $sentTo->union($receivedFrom)->pluck('user_id');

            $conversations = [];
            foreach ($conversationUserIds as $userId) {
                $latestMessage = \App\Models\Message::where(function ($query) use ($user, $userId) {
                    $query->where('s_id', $user->id)->where('r_id', $userId);
                })->orWhere(function ($query) use ($user, $userId) {
                    $query->where('s_id', $userId)->where('r_id', $user->id);
                })
                ->with(['sender', 'receiver'])
                ->orderBy('created_at', 'DESC')
                ->first();

                if ($latestMessage) {
                    $unreadCount = \App\Models\Message::where('s_id', $userId)
                        ->where('r_id', $user->id)
                        ->where('is_read', false)
                        ->count();

                    $participant = $latestMessage->s_id == $user->id
                        ? $latestMessage->receiver
                        : $latestMessage->sender;

                    $conversations[] = [
                        'id' => $userId,
                        'participant_id' => $userId,
                        'participant_name' => $participant?->name ?? 'Unknown',
                        'participant_email' => $participant?->email ?? '',
                        'last_message' => $latestMessage->content,
                        'last_message_time' => $latestMessage->created_at->toISOString(),
                        'unread_count' => $unreadCount,
                        'is_sender' => $latestMessage->s_id == $user->id,
                    ];
                }
            }

            // Sort by latest message
            usort($conversations, function ($a, $b) {
                return strtotime($b['last_message_time']) - strtotime($a['last_message_time']);
            });

            return response()->json([
                'data' => $conversations
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching conversations: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch conversations',
                'data' => []
            ], 500);
        }
    }

    /**
     * Get messages in a conversation
     * GET /api/student/messages/{conversationId}
     */
    public function getMessages(Request $request, $conversationId)
    {
        try {
            $user = $request->user();

            $messages = \App\Models\Message::where(function ($query) use ($user, $conversationId) {
                $query->where('s_id', $user->id)->where('r_id', $conversationId);
            })->orWhere(function ($query) use ($user, $conversationId) {
                $query->where('s_id', $conversationId)->where('r_id', $user->id);
            })
            ->with(['sender', 'receiver', 'attachments'])
            ->orderBy('created_at', 'ASC')
            ->get();

            // Mark messages as read
            \App\Models\Message::where('s_id', $conversationId)
                ->where('r_id', $user->id)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            $formattedMessages = $messages->map(function ($message) use ($user) {
                return [
                    'id' => $message->id,
                    'content' => $message->content,
                    'is_sender' => $message->s_id == $user->id,
                    'sender' => [
                        'id' => $message->sender?->id,
                        'name' => $message->sender?->name,
                        'email' => $message->sender?->email,
                    ],
                    'receiver' => [
                        'id' => $message->receiver?->id,
                        'name' => $message->receiver?->name,
                        'email' => $message->receiver?->email,
                    ],
                    'is_read' => (bool) $message->is_read,
                    'attachments' => $message->attachments->map(function ($attachment) {
                        return [
                            'id' => $attachment->id,
                            'filename' => $attachment->filename ?? 'attachment',
                            'file_path' => $attachment->file_path,
                            'file_url' => Storage::url($attachment->file_path),
                        ];
                    })->toArray(),
                    'created_at' => $message->created_at->toISOString(),
                ];
            });

            return response()->json([
                'data' => $formattedMessages
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching messages: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to fetch messages',
                'data' => []
            ], 500);
        }
    }

    /**
     * Send a message
     * POST /api/student/messages/send
     */
    public function sendMessage(Request $request)
    {
        try {
            $user = $request->user();

            $request->validate([
                'receiver_id' => 'required|exists:users,id',
                'content' => 'required|string|max:5000',
                'attachments' => 'nullable|array',
                'attachments.*' => 'file|mimes:pdf,doc,docx,jpg,png|max:5120', // 5MB
            ]);

            $message = \App\Models\Message::create([
                's_id' => $user->id,
                'r_id' => $request->input('receiver_id'),
                'content' => $request->input('content'),
                'is_read' => false,
            ]);

            // Handle attachments if any
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('message_attachments', 'public');
                    
                    \App\Models\MessageAttachment::create([
                        'message_id' => $message->id,
                        'file_path' => $path,
                        'filename' => $file->getClientOriginalName(),
                    ]);
                }
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

        } catch (\Exception $e) {
            Log::error('Error sending message: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to send message',
            ], 500);
        }
    }

    /**
     * Mark message as read
     * PUT /api/student/messages/{messageId}/read
     */
    public function markAsRead(Request $request, $messageId)
    {
        try {
            $user = $request->user();

            $message = \App\Models\Message::where('id', $messageId)
                ->where('r_id', $user->id)
                ->first();

            if (!$message) {
                return response()->json([
                    'error' => true,
                    'message' => 'Message not found',
                ], 404);
            }

            $message->is_read = true;
            $message->save();

            return response()->json([
                'message' => 'Message marked as read',
            ]);

        } catch (\Exception $e) {
            Log::error('Error marking message as read: ' . $e->getMessage());
            return response()->json([
                'error' => true,
                'message' => 'Failed to mark message as read',
            ], 500);
        }
    }
}