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
        'classGroup', // ✅ add this
    ])->latest('id')->get();

    return response()->json(['data' => $data], 200);
}

// POST: /api/courses
public function store(Request $request)
{
    $validated = $request->validate([
        // allow either one
        'major_subject_id' => 'nullable|exists:major_subjects,id',

        // easy UI way
        'major_id'   => 'nullable|exists:majors,id',
        'subject_id' => 'nullable|exists:subjects,id',

        'teacher_id'    => 'required|exists:teachers,id',
        'semester'      => 'required|integer|min:1|max:3',
        'academic_year' => 'required|string|regex:/^\d{4}-\d{4}$/',
        'class_group_id'=> 'nullable|exists:class_groups,id',
    ]);

    // ✅ Resolve major_subject_id if user sent major_id + subject_id
    $majorSubjectId = $validated['major_subject_id'] ?? null;

    if (!$majorSubjectId) {
        if (empty($validated['major_id']) || empty($validated['subject_id'])) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => [
                    'major_subject_id' => ['major_subject_id OR (major_id + subject_id) is required.']
                ]
            ], 422);
        }

        $ms = \App\Models\MajorSubject::where('major_id', (int)$validated['major_id'])
            ->where('subject_id', (int)$validated['subject_id'])
            ->first();

        if (!$ms) {
            return response()->json([
                'message' => 'Major-Subject mapping not found. Please assign subject to major first.',
                'errors' => [
                    'subject_id' => ['This subject is not assigned to the selected major.']
                ]
            ], 422);
        }

        $majorSubjectId = $ms->id;
    }

    // ✅ Prevent duplicates (same major_subject + academic_year + semester + class_group)
    $exists = \App\Models\Course::where('major_subject_id', $majorSubjectId)
        ->where('teacher_id', (int)$validated['teacher_id'])
        ->where('semester', (int)$validated['semester'])
        ->where('academic_year', (string)$validated['academic_year'])
        ->when(array_key_exists('class_group_id', $validated), function ($q) use ($validated) {
            $q->where('class_group_id', $validated['class_group_id']);
        })
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Course already exists for this subject/major/semester/year (and class group).'
        ], 409);
    }

    $course = \App\Models\Course::create([
        'major_subject_id' => $majorSubjectId,
        'teacher_id'       => (int)$validated['teacher_id'],
        'semester'         => (int)$validated['semester'],
        'academic_year'    => (string)$validated['academic_year'],
        'class_group_id'   => $validated['class_group_id'] ?? null,
    ]);

    return response()->json(['data' => $course], 201);
}



    // GET: /api/courses/{id}
public function show($id)
{
    $course = Course::with([
        'majorSubject.major',
        'majorSubject.subject',
        'teacher',
        'classGroup', // ✅ add this
    ])->findOrFail($id);

    return response()->json(['data' => $course], 200);
}

    // PUT: /api/courses/{id}
// PUT: /api/courses/{id}
public function update(Request $request, $id)
{
    $course = Course::findOrFail($id);

    $validated = $request->validate([
        'teacher_id'     => 'required|exists:teachers,id',
        'semester'       => 'required|integer|min:1|max:3',
        'academic_year'  => 'required|string|regex:/^\d{4}-\d{4}$/',
        'class_group_id' => 'nullable|exists:class_groups,id',
    ]);

    // ✅ Prevent duplicate course (except itself)
    $exists = Course::where('major_subject_id', $course->major_subject_id)
        ->where('semester', $validated['semester'])
        ->where('academic_year', $validated['academic_year'])
        ->when(array_key_exists('class_group_id', $validated), function ($q) use ($validated) {
            $q->where('class_group_id', $validated['class_group_id']);
        })
        ->where('id', '!=', $course->id)
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Course already exists for this subject, semester, academic year, and class group.'
        ], 409);
    }

    $course->update($validated);

    // ✅ Return with relations so UI refreshes correctly
    return response()->json([
        'data' => $course->load([
            'majorSubject.major',
            'majorSubject.subject',
            'teacher',
            'classGroup',
        ])
    ], 200);
}



    // DELETE: /api/courses/{id}
    public function destroy($id)
    {
        Course::findOrFail($id)->delete();

        return response()->json(['message' => 'Course deleted'], 200);
    }
}
