<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

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

    // POST: /api/courses
    public function store(Request $request)
    {
        $validated = $request->validate([
            'major_subject_id' => 'required|integer|exists:major_subjects,id',
            'teacher_id'       => 'required|integer|exists:teachers,id',
            'semester'         => 'required|integer|min:1|max:3',
            'academic_year'    => 'required|integer|min:2000|max:2100',
        ]);

        $course = Course::create($validated);

        return response()->json([
            'message' => 'Course created',
            'data' => $course->load([
                'majorSubject.major',
                'majorSubject.subject',
                'teacher',
            ]),
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
            'major_subject_id' => 'required|integer|exists:major_subjects,id',
            'teacher_id'       => 'required|integer|exists:teachers,id',
            'semester'         => 'required|integer|min:1|max:3',
            'academic_year'    => 'required|integer|min:2000|max:2100',
        ]);

        $course->update($validated);

        return response()->json([
            'message' => 'Course updated',
            'data' => $course->load([
                'majorSubject.major',
                'majorSubject.subject',
                'teacher',
            ]),
        ], 200);
    }

    // DELETE: /api/courses/{id}
    public function destroy($id)
    {
        Course::findOrFail($id)->delete();

        return response()->json(['message' => 'Course deleted'], 200);
    }
}
