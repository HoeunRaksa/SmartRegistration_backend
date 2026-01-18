<?php

namespace App\Http\Controllers;

use App\Models\Major;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $validated = $request->validate([
            'major_name'        => 'required|string|max:255',
            'description'       => 'nullable|string',
            'department_id'     => 'required|exists:departments,id',
            'registration_fee'  => 'nullable|numeric|min:0|max:99999999.99',
            'image'             => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ]);

        // Set default fee if not provided
        if (!isset($validated['registration_fee'])) {
            $validated['registration_fee'] = 100.00;
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            
            // Create directory if it doesn't exist
            $uploadPath = public_path('uploads/majors');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }
            
            // Store file in public/uploads/majors directory
            $file->move($uploadPath, $filename);
            
            $validated['image'] = 'uploads/majors/' . $filename;
        }

        $major = Major::create($validated);

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

        $validated = $request->validate([
            'major_name'        => 'sometimes|required|string|max:255',
            'description'       => 'nullable|string',
            'department_id'     => 'sometimes|required|exists:departments,id',
            'registration_fee'  => 'nullable|numeric|min:0|max:99999999.99',
            'image'             => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ]);

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($major->image && file_exists(public_path($major->image))) {
                unlink(public_path($major->image));
            }

            $file = $request->file('image');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            
            // Create directory if it doesn't exist
            $uploadPath = public_path('uploads/majors');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }
            
            // Store file in public/uploads/majors directory
            $file->move($uploadPath, $filename);
            
            $validated['image'] = 'uploads/majors/' . $filename;
        }

        $major->update($validated);

        return response()->json($major);
    }

    // DELETE: /api/majors/{id}
    public function destroy($id)
    {
        $major = Major::findOrFail($id);
        
        // Delete image if exists
        if ($major->image && file_exists(public_path($major->image))) {
            unlink(public_path($major->image));
        }
        
        $major->delete();

        return response()->json(['message' => 'Major deleted']);
    }

    // GET: /api/majors/{id}/fee - Get registration fee for a specific major
    public function getFee($id)
    {
        $major = Major::findOrFail($id);

        return response()->json([
            'major_id' => $major->id,
            'major_name' => $major->major_name,
            'registration_fee' => $major->registration_fee,
            'currency' => 'USD'
        ]);
    }
}