<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    /**
     * GET /api/subjects
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Subject::all()
        ]);
    }

    /**
     * POST /api/subjects
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject_name' => 'required|string|max:255',
            'description'  => 'nullable|string',
            'credit'       => 'required|integer|min:1',
        ]);

        $subject = Subject::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Subject created successfully',
            'data' => $subject
        ], 201);
    }

    /**
     * GET /api/subjects/{id}
     */
    public function show($id)
    {
        $subject = Subject::with('majorSubjects')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $subject
        ]);
    }

    /**
     * PUT /api/subjects/{id}
     */
    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        $validated = $request->validate([
            'subject_name' => 'required|string|max:255',
            'description'  => 'nullable|string',
            'credit'       => 'required|integer|min:1',
        ]);

        $subject->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Subject updated successfully',
            'data' => $subject
        ]);
    }

    /**
     * DELETE /api/subjects/{id}
     */
    public function destroy($id)
    {
        Subject::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully'
        ]);
    }
}
