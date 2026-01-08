<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * GET all departments
     */
    public function index()
    {
        return response()->json(
            Department::latest()->get()
        );
    }

    /**
     * STORE department
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'code'           => 'required|string|max:50|unique:departments,code',
            'faculty'        => 'nullable|string|max:255',
            'title'          => 'nullable|string|max:255',
            'description'    => 'nullable|string',
            'contact_email'  => 'nullable|email',
            'phone_number'   => 'nullable|string|max:50',
            'image'          => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // ✅ THIS IS THE MISSING PART
        if ($request->hasFile('image')) {
            $file = $request->file('image');

            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('departments', $filename, 'public');

            $validated['image_path'] = $path;
        }

        $department = Department::create($validated);

        return response()->json([
            'message' => 'Department created successfully',
            'data' => $department,
        ], 201);
    }
}
