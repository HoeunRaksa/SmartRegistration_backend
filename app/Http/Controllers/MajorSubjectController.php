<?php

namespace App\Http\Controllers;

use App\Models\MajorSubject;
use Illuminate\Http\Request;

class MajorSubjectController extends Controller
{
    // GET: /api/major-subjects
    public function index()
    {
        $data = MajorSubject::with(['major', 'subject'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $data
        ], 200);
    }

    // POST: /api/major-subjects
    public function store(Request $request)
    {
        $validated = $request->validate([
            'major_id'   => 'required|integer|exists:majors,id',
            'subject_id' => 'required|integer|exists:subjects,id',
        ]);

        // Prevent duplicates (same behavior you already want)
        $majorSubject = MajorSubject::firstOrCreate(
            [
                'major_id'   => $validated['major_id'],
                'subject_id' => $validated['subject_id'],
            ]
        );

        // ✅ firstOrCreate might return existing row -> return 200
        // ✅ if newly created -> return 201
        $status = $majorSubject->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'message' => $majorSubject->wasRecentlyCreated
                ? 'Major subject created'
                : 'Major subject already exists',
            'data' => $majorSubject->load(['major', 'subject'])
        ], $status);
    }

    // GET: /api/major-subjects/{id}
    public function show($id)
    {
        $data = MajorSubject::with([
                'major',
                'subject',
                'courses.teacher'
            ])
            ->findOrFail($id);

        return response()->json([
            'data' => $data
        ], 200);
    }

    // DELETE: /api/major-subjects/{id}
    public function destroy($id)
    {
        $row = MajorSubject::findOrFail($id);
        $row->delete();

        return response()->json([
            'message' => 'Major subject removed'
        ], 200);
    }
}
