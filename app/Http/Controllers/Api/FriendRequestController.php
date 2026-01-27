<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\FriendRequest;
use App\Models\Student;
use App\Models\StudentClassGroup;
use Illuminate\Support\Facades\DB;

class FriendRequestController extends Controller
{
    public function searchStudents(Request $request)
    {
        $user = $request->user();
        if (!$user->student) {
            return response()->json(['success' => false, 'message' => 'Not a student'], 403);
        }

        $myId = $user->student->id;
        $myClassGroups = StudentClassGroup::where('student_id', $myId)
            ->pluck('class_group_id')
            ->toArray();

        $friendIds = FriendRequest::where(function($q) use ($myId) {
                $q->where('sender_id', $myId)->orWhere('receiver_id', $myId);
            })
            ->where('status', 'accepted')
            ->get()
            ->map(function($f) use ($myId) {
                return $f->sender_id == $myId ? $f->receiver_id : $f->sender_id;
            })
            ->toArray();

        $query = Student::query();

        // If searching, allow finding anyone but need to add/request
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('full_name_en', 'like', "%$search%")
                  ->orWhere('student_code', 'like', "%$search%");
            });
        }

        // Filter: same class group OR already friends
        $query->where(function($q) use ($myClassGroups, $friendIds, $myId) {
            $q->whereHas('classGroups', function($sq) use ($myClassGroups) {
                $sq->whereIn('class_groups.id', $myClassGroups);
            })
            ->orWhereIn('id', $friendIds);
        })
        ->where('id', '!=', $myId);

        return response()->json(['success' => true, 'data' => $query->limit(20)->get()]);
    }

    public function sendRequest(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:students,id',
        ]);

        $senderId = $request->user()->student->id;
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
            return response()->json(['message' => 'Request already exists', 'status' => $exists->status], 409);
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
        if ($fr->receiver_id != $request->user()->student->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $fr->update(['status' => 'accepted']);
        return response()->json(['success' => true]);
    }
}
