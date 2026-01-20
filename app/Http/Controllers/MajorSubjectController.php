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

        return response()->json(['data' => $data], 200);
    }

    // POST: /api/major-subjects
    public function store(Request $request)
    {
        $validated = $request->validate([
            'major_id'     => 'required|integer|exists:majors,id',
            'subject_id'   => 'required|integer|exists:subjects,id',

            // ✅ new fields
            'year_level'   => 'nullable|integer|min:1|max:10',
            'semester'     => 'nullable|string|max:20', // ex: "Semester 1"
            'is_required'  => 'nullable|boolean',
        ]);

        // Prevent duplicates for (major_id, subject_id)
        $majorSubject = MajorSubject::firstOrCreate(
            [
                'major_id'   => $validated['major_id'],
                'subject_id' => $validated['subject_id'],
            ],
            [
                // ✅ only used when creating NEW row
                'year_level'  => $validated['year_level'] ?? null,
                'semester'    => $validated['semester'] ?? null,
                'is_required' => $validated['is_required'] ?? true,
            ]
        );

        // If already existed, you may want to UPDATE extra fields (optional but real-world useful)
        // ✅ This keeps your "no duplicate" rule but still lets admin edit values by re-submitting.
        if (! $majorSubject->wasRecentlyCreated) {
            $majorSubject->update([
                'year_level'  => $validated['year_level'] ?? $majorSubject->year_level,
                'semester'    => $validated['semester'] ?? $majorSubject->semester,
                'is_required' => array_key_exists('is_required', $validated)
                    ? (bool)$validated['is_required']
                    : $majorSubject->is_required,
            ]);
        }

        $status = $majorSubject->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'message' => $majorSubject->wasRecentlyCreated
                ? 'Major subject created'
                : 'Major subject already exists (updated)',
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

        return response()->json(['data' => $data], 200);
    }

    // DELETE: /api/major-subjects/{id}
    public function destroy($id)
    {
        $row = MajorSubject::findOrFail($id);
        $row->delete();

        return response()->json(['message' => 'Major subject removed'], 200);
    }
}
