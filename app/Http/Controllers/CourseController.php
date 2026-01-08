<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    // GET: /api/courses
    public function index()
    {
        return response()->json(
            Course::with([
                'majorSubject.major',
                'majorSubject.subject',
                'teacher'
            ])->get()
        );
    }

    // POST: /api/courses
    public function store(Request $request)
    {
        $request->validate([
            'major_subject_id' => 'required|exists:major_subjects,id',
            'teacher_id'       => 'required|exists:teachers,id',
            'semester'         => 'required|string|max:20',
            'academic_year'    => 'required|string|max:20',
        ]);

        $course = Course::create($request->all());

        return response()->json($course, 201);
    }

    // GET: /api/courses/{id}
    public function show($id)
    {
        return response()->json(
            Course::with([
                'majorSubject.major',
                'majorSubject.subject',
                'teacher'
            ])->findOrFail($id)
        );
    }

    // PUT: /api/courses/{id}
    public function update(Request $request, $id)
    {
        $course = Course::findOrFail($id);

        $request->validate([
            'teacher_id'    => 'required|exists:teachers,id',
            'semester'      => 'required|string|max:20',
            'academic_year' => 'required|string|max:20',
        ]);

        $course->update($request->all());

        return response()->json($course);
    }

    // DELETE
    public function destroy($id)
    {
        Course::findOrFail($id)->delete();

        return response()->json(['message' => 'Course deleted']);
    }
}
