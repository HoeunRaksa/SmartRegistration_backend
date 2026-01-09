<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DepartmentController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => Department::latest()->get()
        ]);
    }

    public function show(Department $department)
    {
        return response()->json([
            'success' => true,
            'data' => $department
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'code'          => 'required|string|max:50|unique:departments,code',
            'faculty'       => 'nullable|string|max:255',
            'title'         => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'contact_email' => 'nullable|email',
            'phone_number'  => 'nullable|string|max:50',
            'image'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        if ($request->hasFile('image')) {
            $filename = time() . '_' . uniqid() . '.' . $request->image->extension();
            $validated['image_path'] = $request->image->storeAs(
                'departments',
                $filename,
                'public'
            );
        }

        $department = Department::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Department created successfully',
            'data' => $department
        ], 201);
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'code'          => 'sometimes|required|string|max:50|unique:departments,code,' . $department->id,
            'faculty'       => 'nullable|string|max:255',
            'title'         => 'nullable|string|max:255',
            'description'   => 'nullable|string',
            'contact_email' => 'nullable|email',
            'phone_number'  => 'nullable|string|max:50',
            'image'         => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        if ($request->hasFile('image')) {
            if ($department->image_path) {
                Storage::disk('public')->delete($department->image_path);
            }

            $filename = time() . '_' . uniqid() . '.' . $request->image->extension();
            $validated['image_path'] = $request->image->storeAs(
                'departments',
                $filename,
                'public'
            );
        }

        $department->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Department updated successfully',
            'data' => $department
        ]);
    }

    public function destroy(Department $department)
    {
        if ($department->image_path) {
            Storage::disk('public')->delete($department->image_path);
        }

        $department->delete();

        return response()->json([
            'success' => true,
            'message' => 'Department deleted successfully'
        ]);
    }
}
