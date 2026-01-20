<?php

namespace App\Http\Controllers;

use App\Models\MajorSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'year_level'   => 'nullable|integer|min:1|max:10',
            'semester'     => 'nullable|string|max:20',
            'is_required'  => 'nullable|boolean',
        ]);

        $majorSubject = MajorSubject::firstOrCreate(
            [
                'major_id'   => $validated['major_id'],
                'subject_id' => $validated['subject_id'],
            ],
            [
                'year_level'  => $validated['year_level'] ?? null,
                'semester'    => $validated['semester'] ?? null,
                'is_required' => array_key_exists('is_required', $validated)
                    ? (bool)$validated['is_required']
                    : true,
            ]
        );

        // if exists -> update optional fields (real world)
        if (! $majorSubject->wasRecentlyCreated) {
            $majorSubject->update([
                'year_level'  => $validated['year_level'] ?? $majorSubject->year_level,
                'semester'    => $validated['semester'] ?? $majorSubject->semester,
                'is_required' => array_key_exists('is_required', $validated)
                    ? (bool)$validated['is_required']
                    : $majorSubject->is_required,
            ]);
        }

        return response()->json([
            'message' => $majorSubject->wasRecentlyCreated
                ? 'Major subject created'
                : 'Major subject already exists (updated)',
            'data' => $majorSubject->load(['major', 'subject']),
        ], $majorSubject->wasRecentlyCreated ? 201 : 200);
    }

    // ✅ POST: /api/major-subjects/bulk
    public function storeBulk(Request $request)
    {
        $validated = $request->validate([
            'major_id'     => 'required|integer|exists:majors,id',
            'subject_ids'  => 'required|array|min:1',
            'subject_ids.*'=> 'integer|exists:subjects,id',

            'year_level'   => 'nullable|integer|min:1|max:10',
            'semester'     => 'nullable|string|max:20',
            'is_required'  => 'nullable|boolean',
        ]);

        $majorId = (int) $validated['major_id'];
        $subjectIds = array_values(array_unique(array_map('intval', $validated['subject_ids'])));

        $yearLevel = $validated['year_level'] ?? null;
        $semester  = $validated['semester'] ?? null;
        $isRequired = array_key_exists('is_required', $validated) ? (bool)$validated['is_required'] : true;

        $created = [];
        $updated = [];

        DB::beginTransaction();
        try {
            foreach ($subjectIds as $subjectId) {
                $row = MajorSubject::firstOrCreate(
                    [
                        'major_id'   => $majorId,
                        'subject_id' => $subjectId,
                    ],
                    [
                        'year_level'  => $yearLevel,
                        'semester'    => $semester,
                        'is_required' => $isRequired,
                    ]
                );

                if ($row->wasRecentlyCreated) {
                    $created[] = $row->id;
                } else {
                    // update fields (so bulk can fix year/semester/required)
                    $row->update([
                        'year_level'  => $yearLevel,
                        'semester'    => $semester,
                        'is_required' => $isRequired,
                    ]);
                    $updated[] = $row->id;
                }
            }

            DB::commit();

            $rows = MajorSubject::with(['major', 'subject'])
                ->where('major_id', $majorId)
                ->whereIn('subject_id', $subjectIds)
                ->get();

            return response()->json([
                'message' => 'Bulk assign done',
                'created_count' => count($created),
                'updated_count' => count($updated),
                'data' => $rows,
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Bulk assign failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // GET: /api/major-subjects/{id}
    public function show($id)
    {
        $data = MajorSubject::with([
                'major',
                'subject',
                'courses.teacher'
            ])->findOrFail($id);

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
