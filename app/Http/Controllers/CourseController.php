<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;
use App\Models\MajorSubject;
class CourseController extends Controller
{
    // GET: /api/courses
    public function index()
    {
        $data = Course::with([
            'majorSubject.major',
            'majorSubject.subject',
            'teacher',
        ])->latest('id')->get();

        return response()->json(['data' => $data], 200);
    }

public function store(Request $request)
{
    $validated = $request->validate([
        'major_id'       => 'required|exists:majors,id',
        'subject_id'     => 'required|exists:subjects,id',
        'teacher_id'     => 'required|exists:teachers,id',
        'academic_year'  => 'required|string|regex:/^\d{4}-\d{4}$/',
        'class_group_id' => 'nullable|exists:class_groups,id',
        'semester'       => 'nullable|integer|min:1|max:3',
    ]);

    // ✅ find mapping row (major_subject_id)
    $ms = MajorSubject::where('major_id', $validated['major_id'])
        ->where('subject_id', $validated['subject_id'])
        ->first();

    if (!$ms) {
        return response()->json([
            'message' => 'This subject is not assigned to this major yet. Please map it first in MajorSubjects.'
        ], 422);
    }

    // ✅ default semester from mapping if not provided
    $semester = $validated['semester'] ?? $ms->semester ?? 1;

    // ✅ prevent duplicates (optional but safe)
    $exists = Course::where('major_subject_id', $ms->id)
        ->where('teacher_id', $validated['teacher_id'])
        ->where('academic_year', $validated['academic_year'])
        ->where('semester', $semester)
        ->when(isset($validated['class_group_id']), fn($q) => $q->where('class_group_id', $validated['class_group_id']))
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Course already exists for this major/subject/teacher/semester/year.'
        ], 409);
    }

    $course = Course::create([
        'major_subject_id' => $ms->id,
        'teacher_id'       => $validated['teacher_id'],
        'semester'         => $semester,
        'academic_year'    => $validated['academic_year'],
        'class_group_id'   => $validated['class_group_id'] ?? null,
    ]);

    return response()->json([
        'message' => 'Course created successfully',
        'data' => $course->load(['majorSubject.major','majorSubject.subject','teacher','classGroup'])
    ], 201);
}



    // GET: /api/courses/{id}
    public function show($id)
    {
        $course = Course::with([
            'majorSubject.major',
            'majorSubject.subject',
            'teacher',
        ])->findOrFail($id);

        return response()->json(['data' => $course], 200);
    }

    // PUT: /api/courses/{id}
public function update(Request $request, $id)
{
    $course = Course::findOrFail($id);

    $validated = $request->validate([
        'teacher_id'    => 'required|exists:teachers,id',
        'semester'      => 'required|integer|min:1|max:3',
        'academic_year' => 'required|string|regex:/^\d{4}-\d{4}$/',
    ]);

    $course->update($validated);

    return response()->json($course);
}


    // DELETE: /api/courses/{id}
    public function destroy($id)
    {
        Course::findOrFail($id)->delete();

        return response()->json(['message' => 'Course deleted'], 200);
    }
}
