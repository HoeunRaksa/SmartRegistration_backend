<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassGroup;
use App\Services\ClassGroupAllocator;
use Illuminate\Http\Request;

class ClassGroupController extends Controller
{
    // GET /api/class-groups
    public function index(Request $request)
    {
        $q = ClassGroup::query()->with(['major']);

        // optional filters
        if ($request->filled('major_id')) {
            $q->where('major_id', $request->major_id);
        }
        if ($request->filled('academic_year')) {
            $q->where('academic_year', $request->academic_year);
        }
        if ($request->filled('semester')) {
            $q->where('semester', $request->semester);
        }
        if ($request->filled('shift')) {
            $q->where('shift', $request->shift);
        }

        $rows = $q->latest('id')->get();

        return response()->json(['data' => $rows], 200);
    }

    // POST /api/class-groups
    public function store(Request $request)
    {
        $validated = $request->validate([
            'class_name'    => 'required|string|max:100',
            'major_id'      => 'required|exists:majors,id',
            'academic_year' => 'required|string|regex:/^\d{4}-\d{4}$/',
            'semester' => 'required|integer|in:1,2',
            'shift'         => 'nullable|string|max:50',
           'capacity' => 'required|integer|min:10|max:120',

        ]);

        // prevent duplicate group name in same major/year/semester/shift
        $exists = ClassGroup::where('major_id', (int)$validated['major_id'])
            ->where('academic_year', (string)$validated['academic_year'])
            ->where('semester', (int)$validated['semester'])
            ->where('class_name', (string)$validated['class_name'])
            ->when(array_key_exists('shift', $validated), function ($q) use ($validated) {
                // only filter shift if provided (null allowed)
                if ($validated['shift'] !== null && $validated['shift'] !== '') {
                    $q->where('shift', $validated['shift']);
                } else {
                    $q->where(function ($w) {
                        $w->whereNull('shift')->orWhere('shift', '');
                    });
                }
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Class group already exists for this major/year/semester (and shift).'
            ], 409);
        }

        $row = ClassGroup::create($validated);

        return response()->json(['data' => $row], 201);
    }

    // GET /api/class-groups/{id}
    public function show($id)
    {
        $row = ClassGroup::with(['major', 'courses'])->findOrFail($id);
        return response()->json(['data' => $row], 200);
    }

    // PUT /api/class-groups/{id}
    public function update(Request $request, $id)
    {
        $row = ClassGroup::findOrFail($id);

        $validated = $request->validate([
            'class_name'    => 'required|string|max:100',
            'major_id'      => 'required|exists:majors,id',
            'academic_year' => 'required|string|regex:/^\d{4}-\d{4}$/',
            'semester' => 'required|integer|in:1,2',
            'shift'         => 'nullable|string|max:50',
            'capacity' => 'required|integer|min:10|max:120',

        ]);
         
        $exists = ClassGroup::where('id', '!=', $row->id)
            ->where('major_id', (int)$validated['major_id'])
            ->where('academic_year', (string)$validated['academic_year'])
            ->where('semester', (int)$validated['semester'])
            ->where('class_name', (string)$validated['class_name'])
            ->when(array_key_exists('shift', $validated), function ($q) use ($validated) {
                if ($validated['shift'] !== null && $validated['shift'] !== '') {
                    $q->where('shift', $validated['shift']);
                } else {
                    $q->where(function ($w) {
                        $w->whereNull('shift')->orWhere('shift', '');
                    });
                }
            })
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Another class group already exists with the same major/year/semester (and shift).'
            ], 409);
        }

        $row->update($validated);

        return response()->json(['data' => $row], 200);
    }

    // DELETE /api/class-groups/{id}
    public function destroy($id)
    {
        $row = ClassGroup::findOrFail($id);

        if ($row->courses()->exists()) {
            return response()->json([
                'message' => 'Cannot delete class group because it has courses.'
            ], 409);
        }

        $row->delete();

        return response()->json(['message' => 'Class group deleted'], 200);
    }

    /**
     * ✅ NOTE:
     * We did NOT add any new endpoint.
     * Auto-create & assign happens when you call the allocator from paymentCallback or enrollment flow.
     */
}
