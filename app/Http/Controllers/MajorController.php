<?php

namespace App\Http\Controllers;

use App\Models\Major;
use Illuminate\Http\Request;

class MajorController extends Controller
{
    // GET: /api/majors
    public function index()
    {
        return response()->json(
            Major::with('department')->get()
        );
    }

    // POST: /api/majors
    public function store(Request $request)
    {
        $request->validate([
            'major_name'     => 'required|string|max:255',
            'description'    => 'nullable|string',
            'department_id'  => 'required|exists:departments,id',
        ]);

        $major = Major::create($request->all());

        return response()->json($major, 201);
    }

    // GET: /api/majors/{id}
    public function show($id)
    {
        $major = Major::with([
            'department',
            'majorSubjects.subject'
        ])->findOrFail($id);

        return response()->json($major);
    }

    // PUT: /api/majors/{id}
    public function update(Request $request, $id)
    {
        $major = Major::findOrFail($id);

        $request->validate([
            'major_name'     => 'required|string|max:255',
            'description'    => 'nullable|string',
            'department_id'  => 'required|exists:departments,id',
        ]);

        $major->update($request->all());

        return response()->json($major);
    }

    // DELETE: /api/majors/{id}
    public function destroy($id)
    {
        Major::findOrFail($id)->delete();

        return response()->json(['message' => 'Major deleted']);
    }
}
