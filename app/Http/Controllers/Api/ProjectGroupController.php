<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\ProjectGroup;
use App\Models\ProjectGroupMember;
use App\Models\CourseEnrollment;
use Illuminate\Support\Facades\DB;

class ProjectGroupController extends Controller
{
    public function index(Request $request)
    {
        $query = ProjectGroup::with([
            'students', 
            'course.majorSubject.subject', 
            'course.classGroup'
        ]);
        
        if ($request->filled('course_id')) {
            $query->where('course_id', $request->course_id);
        }
        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'name' => 'required|string|max:255',
            'max_capacity' => 'nullable|integer|min:1|max:50',
        ]);

        $course = \App\Models\Course::findOrFail($validated['course_id']);
        
        // If student is creating, they must be enrolled
        if ($request->user()->role === 'student') {
            $isEnrolled = \App\Models\CourseEnrollment::where('course_id', $course->id)
                ->where('student_id', $request->user()->student->id)
                ->exists();
            if (!$isEnrolled) {
                return response()->json(['success' => false, 'message' => 'You are not enrolled in this course.'], 403);
            }
        }

        $group = ProjectGroup::create([
            'course_id' => $validated['course_id'],
            'teacher_id' => $course->teacher_id ?? 1,
            'creator_id' => $request->user()->id,
            'name' => $validated['name'],
            'max_capacity' => $validated['max_capacity'] ?? 10,
        ]);

        // Auto-join the creator if they are a student
        if ($request->user()->role === 'student') {
            ProjectGroupMember::create([
                'project_group_id' => $group->id,
                'student_id' => $request->user()->student->id,
            ]);
        }

        return response()->json(['success' => true, 'data' => $group->load('students')], 201);
    }

    public function join(Request $request, $id)
    {
        $group = ProjectGroup::findOrFail($id);
        $studentId = $request->user()->student->id;

        // Ensure student is enrolled in the course
        $enrolled = \App\Models\CourseEnrollment::where('course_id', $group->course_id)
            ->where('student_id', $studentId)
            ->exists();
        if (!$enrolled) {
            return response()->json(['success' => false, 'message' => 'You are not enrolled in this course.'], 403);
        }

        // Check if already in a group for THIS course
        $alreadyInGroup = ProjectGroupMember::whereHas('group', function($q) use ($group) {
            $q->where('course_id', $group->course_id);
        })->where('student_id', $studentId)->exists();

        if ($alreadyInGroup) {
            return response()->json(['success' => false, 'message' => 'You are already in a group for this course.'], 400);
        }

        // Check capacity
        if ($group->students()->count() >= $group->max_capacity) {
            return response()->json(['success' => false, 'message' => 'Group is full.'], 400);
        }

        ProjectGroupMember::create([
            'project_group_id' => $group->id,
            'student_id' => $studentId,
        ]);

        return response()->json(['success' => true, 'data' => $group->load('students')]);
    }

    public function leave(Request $request, $id)
    {
        $group = ProjectGroup::findOrFail($id);
        $studentId = $request->user()->student->id;

        $member = ProjectGroupMember::where('project_group_id', $id)
            ->where('student_id', $studentId)
            ->first();

        if (!$member) {
            return response()->json(['success' => false, 'message' => 'You are not a member of this group.'], 400);
        }

        $member->delete();

        // If group is empty, maybe delete it or leave it? User didn't specify. 
        // For now, let's keep it.

        return response()->json(['success' => true, 'message' => 'Left group successfully.']);
    }

    public function destroy(Request $request, $id)
    {
        $group = ProjectGroup::findOrFail($id);
        
        // Only teacher or creator can delete
        if ($request->user()->role !== 'teacher' && $group->creator_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Only the team creator or instructor can delete this team.'], 403);
        }

        $group->delete();
        return response()->json(['success' => true, 'message' => 'Group deleted.']);
    }

    public function autoAssign(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|exists:courses,id',
            'members_per_group' => 'nullable|integer|min:2|max:20',
        ]);

        $courseId = $validated['course_id'];
        $count = $validated['members_per_group'] ?? 5;

        $studentIds = CourseEnrollment::where('course_id', $courseId)
            ->pluck('student_id')
            ->toArray();

        // Remove those already in groups for this course
        $alreadyInGroup = ProjectGroupMember::whereHas('group', function ($q) use ($courseId) {
                $q->where('course_id', $courseId);
            })
            ->pluck('student_id')
            ->toArray();

        $availableIds = array_diff($studentIds, $alreadyInGroup);
        shuffle($availableIds);

        $chunks = array_chunk($availableIds, $count);
        $createdGroups = [];

        DB::beginTransaction();
        try {
            foreach ($chunks as $index => $chunk) {
                $group = ProjectGroup::create([
                    'course_id' => $courseId,
                    'teacher_id' => $request->user()->teacher->id ?? 1,
                    'name' => "Project Group " . (ProjectGroup::where('course_id', $courseId)->count() + 1),
                    'max_capacity' => $count,
                ]);

                foreach ($chunk as $sid) {
                    ProjectGroupMember::create([
                        'project_group_id' => $group->id,
                        'student_id' => $sid,
                    ]);
                }
                $createdGroups[] = $group->load('students');
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'data' => $createdGroups]);
    }
}
