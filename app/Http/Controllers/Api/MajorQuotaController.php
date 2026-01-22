<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MajorQuota;
use Illuminate\Http\Request;

class MajorQuotaController extends Controller
{
    // GET /api/major-quotas?major_id=1&academic_year=2026-2027
    public function index(Request $request)
    {
        $q = MajorQuota::query()->with('major');

        if ($request->filled('major_id')) {
            $q->where('major_id', (int) $request->major_id);
        }
        if ($request->filled('academic_year')) {
            $q->where('academic_year', (string) $request->academic_year);
        }

        return response()->json([
            'success' => true,
            'data' => $q->latest('id')->get()
        ]);
    }

    // POST /api/major-quotas
    public function store(Request $request)
    {
        $validated = $request->validate([
            'major_id' => 'required|exists:majors,id',
            'academic_year' => ['required', 'string', 'max:20', 'regex:/^\d{4}-\d{4}$/'],
            'limit' => 'required|integer|min:1|max:20000',
            'opens_at' => 'nullable|date',
            'closes_at' => 'nullable|date|after:opens_at',
        ]);


        $row = MajorQuota::updateOrCreate(
            [
                'major_id' => (int) $validated['major_id'],
                'academic_year' => (string) $validated['academic_year'],
            ],
            [
                'limit' => (int) $validated['limit'],
                'opens_at' => $validated['opens_at'] ?? null,
                'closes_at' => $validated['closes_at'] ?? null,
            ]
        );

        return response()->json(['success' => true, 'data' => $row], 201);
    }

    // PUT /api/major-quotas/{id}
    public function update(Request $request, $id)
    {
        $row = MajorQuota::findOrFail($id);

   $validated = $request->validate([
    'limit' => 'required|integer|min:1|max:20000',
    'opens_at' => 'nullable|date',
    'closes_at' => 'nullable|date|after:opens_at',
]);
$row->update([
    'limit' => (int) $validated['limit'],
    'opens_at' => $validated['opens_at'] ?? null,
    'closes_at' => $validated['closes_at'] ?? null,
]);


        return response()->json(['success' => true, 'data' => $row]);
    }

    // DELETE /api/major-quotas/{id}
    public function destroy($id)
    {
        $row = MajorQuota::findOrFail($id);
        $row->delete();

        return response()->json(['success' => true, 'message' => 'Quota deleted']);
    }
}
