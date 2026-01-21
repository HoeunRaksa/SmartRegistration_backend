<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MajorSubject;
use Illuminate\Http\Request;

class MajorSubjectController extends Controller
{
    public function index()
    {
        $rows = MajorSubject::with(['major', 'subject'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $rows
        ]);
    }

    public function storeBulk(Request $request)
    {
        $data = $request->validate([
            'major_id' => 'required|exists:majors,id',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'year_level' => 'nullable|integer|min:1|max:10',
            'semester' => 'nullable|string|max:20',
            'is_required' => 'nullable|boolean',
        ]);

        $majorId = (int) $data['major_id'];
        $subjectIds = collect($data['subject_ids'])
            ->map(fn ($x) => (int) $x)
            ->unique()
            ->values();

        $yearLevel = array_key_exists('year_level', $data) ? $data['year_level'] : null;
        $semester = array_key_exists('semester', $data) ? $data['semester'] : null;
        $isRequired = array_key_exists('is_required', $data) ? (bool) $data['is_required'] : true;

        // existing subject ids for this major
        $existing = MajorSubject::where('major_id', $majorId)
            ->whereIn('subject_id', $subjectIds)
            ->pluck('subject_id')
            ->map(fn ($x) => (int) $x);

        $toInsert = $subjectIds->diff($existing)->values();

        $now = now();
        $rows = $toInsert->map(function ($sid) use ($majorId, $yearLevel, $semester, $isRequired, $now) {
            return [
                'major_id' => $majorId,
                'subject_id' => (int) $sid,
                'year_level' => $yearLevel,
                'semester' => $semester,
                'is_required' => $isRequired,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        if (!empty($rows)) {
            MajorSubject::insert($rows);
        }

        // Optional: update fields for existing rows too (if you want consistent metadata)
        MajorSubject::where('major_id', $majorId)
            ->whereIn('subject_id', $subjectIds)
            ->update([
                'year_level' => $yearLevel,
                'semester' => $semester,
                'is_required' => $isRequired,
                'updated_at' => now(),
            ]);

        $result = MajorSubject::with(['major', 'subject'])
            ->where('major_id', $majorId)
            ->whereIn('subject_id', $subjectIds)
            ->get();

        return response()->json([
            'message' => 'Bulk mapping completed',
            'data' => $result,
        ], 201);
    }

    public function destroy($id)
    {
        $row = MajorSubject::findOrFail($id);
        $row->delete();

        return response()->json([
            'message' => 'Mapping deleted'
        ]);
    }
}
