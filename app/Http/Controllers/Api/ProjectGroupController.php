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

        $group = ProjectGroup::create([
            'course_id' => $validated['course_id'],
            'teacher_id' => $request->user()->teacher->id ?? 1, // Fallback if user model differs
            'name' => $validated['name'],
            'max_capacity' => $validated['max_capacity'] ?? 10,
        ]);

        return response()->json(['success' => true, 'data' => $group], 201);
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
