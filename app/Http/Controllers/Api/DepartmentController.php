<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Models\Major;

class DepartmentController extends Controller
{
    /**
     * Display a listing of departments with optional filtering
     * 
     * Query Parameters:
     * - search: Search in name, code, faculty, description
     * - faculty: Filter by specific faculty
     * - sort_by: Sort by 'name', 'code', 'created_at' (default: 'name')
     * - sort_order: 'asc' or 'desc' (default: 'asc')
     * - with_stats: Include student count per department (true/false)
     */
    public function index(Request $request)
    {
        try {
            $query = Department::query();

            // Search filter
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('faculty', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            // Faculty filter
            if ($request->filled('faculty')) {
                $query->where('faculty', $request->faculty);
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'name');
            $sortOrder = $request->input('sort_order', 'asc');
            
            $allowedSortFields = ['name', 'code', 'created_at', 'faculty'];
            if (in_array($sortBy, $allowedSortFields)) {
                $query->orderBy($sortBy, $sortOrder);
            } else {
                $query->orderBy('name', 'asc');
            }

            $departments = $query->get();

            // Add student count if requested
            if ($request->boolean('with_stats')) {
                $departments->each(function ($department) {
                    $department->student_count = $department->students()->count();
                    $department->staff_count = $department->staff()->count() ?? 0;
                });
            }

            // Add full URL for images
            $departments->transform(function ($department) {
                if ($department->image_path) {
                    $department->image_url = url($department->image_path);
                }
                return $department;
            });

            return response()->json([
                'success' => true,
                'data' => $departments,
                'meta' => [
                    'total' => $departments->count(),
                    'filters_applied' => $request->only(['search', 'faculty', 'sort_by', 'sort_order'])
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch departments', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch departments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unique faculties for filter dropdown
     */
    public function getFaculties()
    {
        try {
            $faculties = Department::whereNotNull('faculty')
                ->distinct()
                ->pluck('faculty')
                ->sort()
                ->values();

            return response()->json([
                'success' => true,
                'data' => $faculties
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch faculties', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch faculties',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get department statistics
     */
    public function getStatistics()
    {
        try {
            $stats = [
                'total_departments' => Department::count(),
                'by_faculty' => Department::selectRaw('faculty, COUNT(*) as count')
                    ->whereNotNull('faculty')
                    ->groupBy('faculty')
                    ->get(),
                'recent_departments' => Department::orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(['id', 'name', 'code', 'created_at']),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch statistics', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Department $department)
    {
        try {
            // Load relationships
            $department->load(['majors', 'students']);

            // Add statistics
            $department->student_count = $department->students()->count();
            $department->major_count = $department->majors()->count();

            // Add full URL for image
            if ($department->image_path) {
                $department->image_url = url($department->image_path);
            }

            return response()->json([
                'success' => true,
                'data' => $department
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Department not found',
                'error' => $e->getMessage()
            ], 404);
        }
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

        try {
            if ($request->hasFile('image')) {
                // Create directory if not exists
                $uploadPath = public_path('uploads/departments');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                // Generate unique filename
                $image = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // Move to public directory
                $image->move($uploadPath, $filename);
                $validated['image_path'] = 'uploads/departments/' . $filename;
            }

            $department = Department::create($validated);

            // Add full URL for image
            if ($department->image_path) {
                $department->image_url = url($department->image_path);
            }

            return response()->json([
                'success' => true,
                'message' => 'Department created successfully',
                'data' => $department
            ], 201);
        } catch (\Exception $e) {
            // Delete uploaded image if exists
            if (isset($validated['image_path']) && File::exists(public_path($validated['image_path']))) {
                File::delete(public_path($validated['image_path']));
            }

            Log::error('Failed to create department', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create department',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Department $department)
    {
        // Handle _method field from FormData
        if ($request->has('_method')) {
            $request->request->remove('_method');
        }

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

        try {
            $oldImagePath = $department->image_path;

            if ($request->hasFile('image')) {
                // Create directory if not exists
                $uploadPath = public_path('uploads/departments');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                // Generate unique filename
                $image = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // Move to public directory
                $image->move($uploadPath, $filename);
                $validated['image_path'] = 'uploads/departments/' . $filename;

                // Delete old image
                if ($oldImagePath && File::exists(public_path($oldImagePath))) {
                    File::delete(public_path($oldImagePath));
                }
            }

            $department->update($validated);

            // Add full URL for image
            if ($department->image_path) {
                $department->image_url = url($department->image_path);
            }

            return response()->json([
                'success' => true,
                'message' => 'Department updated successfully',
                'data' => $department
            ]);
        } catch (\Exception $e) {
            // Delete uploaded image if exists
            if (isset($validated['image_path']) && File::exists(public_path($validated['image_path']))) {
                File::delete(public_path($validated['image_path']));
            }

            Log::error('Failed to update department', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update department',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Department $department)
    {
        try {
            $imagePath = $department->image_path;

            $department->delete();

            // Delete image if exists
            if ($imagePath && File::exists(public_path($imagePath))) {
                File::delete(public_path($imagePath));
            }

            return response()->json([
                'success' => true,
                'message' => 'Department deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete department', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete department',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function majors(Request $request, $department_id)
    {
        $majors = Major::where('department_id', $department_id)->get(['id', 'major_name']);
        return response()->json([
            'success' => true,
            'data' => $majors
        ]);
    }
}