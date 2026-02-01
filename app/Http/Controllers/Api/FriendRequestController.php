<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\FriendRequest;
use App\Models\Student;
use App\Models\User;
use App\Models\StudentClassGroup;
use Illuminate\Support\Facades\DB;

class FriendRequestController extends Controller
{
    public function searchStudents(Request $request)
    {
        $user = $request->user();
        $myId = $user->id; // Use User ID directly

        $query = User::query()->where('id', '!=', $myId);

        // Filters based on role
        if ($user->role === 'student' && $user->student) {
            $myClassGroups = StudentClassGroup::where('student_id', $user->student->id)
                ->pluck('class_group_id')
                ->toArray();

            // Students can find people in their class groups OR anyone if searching
            if (!$request->filled('search')) {
                $query->whereHas('student.classGroups', function($q) use ($myClassGroups) {
                    $q->whereIn('class_groups.id', $myClassGroups);
                });
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('email', 'like', "%$search%")
                  ->orWhereHas('student', function($sq) use ($search) {
                      $sq->where('student_code', 'like', "%$search%");
                  });
            });
        }

        $results = $query->with(['student', 'teacher'])->limit(20)->get();

        // Attach connection status
        foreach ($results as $item) {
            $existing = FriendRequest::where(function($q) use ($myId, $item) {
                    $q->where('sender_id', $myId)->where('receiver_id', $item->id);
                })->orWhere(function($q) use ($myId, $item) {
                    $q->where('sender_id', $item->id)->where('receiver_id', $myId);
                })->first();

            $item->connection_status = $existing ? $existing->status : null;
            $item->connection_id = $existing ? $existing->id : null;
            
            // For frontend compatibility with studentSide
            $item->full_name_en = $item->name;
            $item->student_code = $item->student ? $item->student->student_code : null;
        }

        return response()->json(['success' => true, 'data' => $results]);
    }

    public function sendRequest(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
        ]);

        $senderId = $request->user()->id;
        $receiverId = $validated['receiver_id'];

        if ($senderId == $receiverId) {
            return response()->json(['message' => 'Cannot add yourself'], 422);
        }

        $exists = FriendRequest::where(function($q) use ($senderId, $receiverId) {
                $q->where('sender_id', $senderId)->where('receiver_id', $receiverId);
            })->orWhere(function($q) use ($senderId, $receiverId) {
                $q->where('sender_id', $receiverId)->where('receiver_id', $senderId);
            })->first();

        if ($exists) {
            return response()->json([
                'message' => 'Request already exists', 
                'status' => $exists->status,
                'data' => $exists
            ], 409);
        }

        $fr = FriendRequest::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'status' => 'pending',
        ]);

        return response()->json(['success' => true, 'data' => $fr]);
    }

    public function acceptRequest(Request $request, $id)
    {
        $fr = FriendRequest::findOrFail($id);
        if ($fr->receiver_id != $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $fr->update(['status' => 'accepted']);
        return response()->json(['success' => true]);
    }

    public function removeConnection(Request $request, $id)
    {
        $fr = FriendRequest::findOrFail($id);
        $userId = $request->user()->id;

        // Ensure the person removing is either the sender or receiver
        if ($fr->sender_id != $userId && $fr->receiver_id != $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $fr->delete();
        return response()->json(['success' => true]);
    }
}
