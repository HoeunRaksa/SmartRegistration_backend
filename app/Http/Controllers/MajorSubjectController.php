<?php

namespace App\Http\Controllers;

use App\Models\MajorSubject;
use Illuminate\Http\Request;

class MajorSubjectController extends Controller
{
    // GET: /api/major-subjects
    public function index()
    {
        return response()->json(
            MajorSubject::with(['major', 'subject'])->get()
        );
    }

    // POST: /api/major-subjects
    public function store(Request $request)
    {
        $request->validate([
            'major_id'   => 'required|exists:majors,id',
            'subject_id' => 'required|exists:subjects,id',
        ]);

        $majorSubject = MajorSubject::firstOrCreate([
            'major_id' => $request->major_id,
            'subject_id' => $request->subject_id,
        ]);


        return response()->json($majorSubject, 201);
    }

    // GET: /api/major-subjects/{id}
    public function show($id)
    {
        return response()->json(
            MajorSubject::with([
                'major',
                'subject',
                'courses.teacher'
            ])->findOrFail($id)
        );
    }

    // DELETE
    public function destroy($id)
    {
        MajorSubject::findOrFail($id)->delete();

        return response()->json(['message' => 'Major subject removed']);
    }
}
