<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubjectController extends Controller
{
    // GET /api/subjects?department_id=1
    public function index(Request $request)
    {
        $query = Subject::query();
        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }
        $subjects = $query->latest('id')->get();
        return response()->json([
            'success' => true,
            'data'    => $subjects,
        ]);
    }

    // POST /api/subjects
    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'subject_name'  => 'required|string|max:255',
            'description'   => 'nullable|string',
            'credit'        => 'required|integer|min:1',
            // allow client to send a code (optional)
            'code'          => 'sometimes|string|max:50',
        ]);

        try {
            $subject = new Subject($data);
            // preserve a client‑provided code (optional); otherwise your model can auto‑generate
            if ($request->filled('code')) {
                $subject->code = $request->input('code');
            }
            $subject->save();

            return response()->json([
                'success' => true,
                'message' => 'Subject created successfully',
                'data'    => $subject,
            ], 201);
        } catch (\Throwable $e) {
            // log the error and return a descriptive message
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subject: '.$e->getMessage(),
            ], 500);
        }
    }

    // GET /api/subjects/{id}
    public function show($id)
    {
        $subject = Subject::with(['majorSubjects', 'department'])->findOrFail($id);
        return response()->json([
            'success' => true,
            'data'    => $subject,
        ]);
    }

    // PUT /api/subjects/{id}
    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);
        $data = $request->validate([
            'department_id' => 'required|integer|exists:departments,id',
            'subject_name'  => 'required|string|max:255',
            'description'   => 'nullable|string',
            'credit'        => 'required|integer|min:1',
            // do not allow clients to change the code here
        ]);

        try {
            $subject->update($data);
            return response()->json([
                'success' => true,
                'message' => 'Subject updated successfully',
                'data'    => $subject,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update subject: '.$e->getMessage(),
            ], 500);
        }
    }

    // DELETE /api/subjects/{id}
    public function destroy($id)
    {
        $subject = Subject::findOrFail($id);
        // optionally check if related models exist before deleting
        $subject->delete();
        return response()->json([
            'success' => true,
            'message' => 'Subject deleted successfully',
        ]);
    }
}
