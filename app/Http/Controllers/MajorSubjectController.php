<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MajorSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

    public function store(Request $request)
    {
        $data = $request->validate([
            'major_id' => 'required|exists:majors,id',
            'subject_id' => 'required|exists:subjects,id',
            'year_level' => 'nullable|integer|min:1|max:10',
            'semester' => 'nullable|string|max:20',
            'is_required' => 'nullable|boolean',
        ]);

        // ✅ prevent duplicates safely
        $row = MajorSubject::firstOrCreate(
            [
                'major_id' => $data['major_id'],
                'subject_id' => $data['subject_id'],
            ],
            [
                'year_level' => $data['year_level'] ?? null,
                'semester' => $data['semester'] ?? null,
                'is_required' => array_key_exists('is_required', $data) ? (bool)$data['is_required'] : true,
            ]
        );

        // If already existed, update optional fields if provided
        $update = [];
        if (array_key_exists('year_level', $data)) $update['year_level'] = $data['year_level'];
        if (array_key_exists('semester', $data)) $update['semester'] = $data['semester'];
        if (array_key_exists('is_required', $data)) $update['is_required'] = (bool)$data['is_required'];

        if (!empty($update)) {
            $row->update($update);
        }

        return response()->json([
            'message' => 'Major-Subject mapped successfully',
            'data' => $row->load(['major', 'subject']),
        ], 201);
    }

    public function bulkStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'major_id' => 'required|exists:majors,id',
            'subject_ids' => 'required|array|min:1',
            'subject_ids.*' => 'integer|exists:subjects,id',
            'year_level' => 'nullable|integer|min:1|max:10',
            'semester' => 'nullable|string|max:20',
            'is_required' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $majorId = (int)$request->major_id;
        $subjectIds = collect($request->subject_ids)->map(fn($x) => (int)$x)->unique()->values();

        $yearLevel = $request->year_level !== null ? (int)$request->year_level : null;
        $semester = $request->semester !== null ? trim((string)$request->semester) : null;
        $isRequired = $request->has('is_required') ? (bool)$request->is_required : true;

        DB::beginTransaction();
        try {
            // ✅ insert only missing pairs
            $existing = MajorSubject::where('major_id', $majorId)
                ->whereIn('subject_id', $subjectIds)
                ->pluck('subject_id')
                ->map(fn($x) => (int)$x);

            $toInsert = $subjectIds->diff($existing)->values();

            $now = now();
            $rows = $toInsert->map(function ($sid) use ($majorId, $yearLevel, $semester, $isRequired, $now) {
                return [
                    'major_id' => $majorId,
                    'subject_id' => (int)$sid,
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

            // ✅ update optional fields for already-existing mappings (if you want)
            MajorSubject::where('major_id', $majorId)
                ->whereIn('subject_id', $subjectIds)
                ->update([
                    'year_level' => $yearLevel,
                    'semester' => $semester,
                    'is_required' => $isRequired,
                    'updated_at' => now(),
                ]);

            DB::commit();

            $result = MajorSubject::with(['major', 'subject'])
                ->where('major_id', $majorId)
                ->whereIn('subject_id', $subjectIds)
                ->get();

            return response()->json([
                'message' => 'Bulk mapping completed',
                'data' => $result
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Bulk mapping failed',
                'error' => $e->getMessage()
            ], 500);
        }
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
