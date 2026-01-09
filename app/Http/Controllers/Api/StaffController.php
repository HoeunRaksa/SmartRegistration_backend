<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class StaffController extends Controller
{
    /**
     * Display a listing of staff members
     */
    public function index(Request $request)
    {
        try {
            $query = Staff::with('user');

            // Filter by department if provided
            if ($request->has('department_id')) {
                $query->where('department_id', $request->department_id);
            }

            // Search functionality
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('full_name_kh', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone_number', 'like', "%{$search}%");
                });
            }

            $staff = $query->paginate($request->per_page ?? 15);

            // Add full URL for profile images
            $staff->getCollection()->transform(function ($item) {
                if ($item->user && $item->user->profile_picture_path) {
                    $item->user->profile_picture_url = url('uploads/profiles/' . basename($item->user->profile_picture_path));
                }
                return $item;
            });

            return response()->json($staff);
        } catch (\Exception $e) {
            Log::error('Failed to fetch staff', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to fetch staff',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified staff member
     */
    public function show($id)
    {
        try {
            $staff = Staff::with('user')->findOrFail($id);
            
            // Add full URL for profile image
            if ($staff->user && $staff->user->profile_picture_path) {
                $staff->user->profile_picture_url = url('uploads/profiles/' . basename($staff->user->profile_picture_path));
            }
            
            return response()->json($staff);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Staff not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Store a newly created staff member
     */
    public function store(Request $request)
    {
        Log::info('Creating staff', [
            'content_type' => $request->header('Content-Type'),
            'has_file' => $request->hasFile('profile_image'),
            'data' => $request->except(['profile_image', 'password'])
        ]);

        $validated = $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,staff',
            'user_name' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
            'department_name' => 'required|string|max:255',
            'full_name' => 'required|string|max:255',
            'full_name_kh' => 'nullable|string|max:255',
            'position' => 'required|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // Sanitize UTF-8
        $validated = $this->sanitizeInput($validated);

        DB::beginTransaction();

        try {
            $imagePath = null;
            if ($request->hasFile('profile_image')) {
                // Create directory if not exists
                $uploadPath = public_path('uploads/profiles');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                // Generate unique filename
                $image = $request->file('profile_image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // Move to public directory
                $image->move($uploadPath, $filename);
                $imagePath = 'uploads/profiles/' . $filename;
            }

            $user = User::create([
                'name' => $validated['full_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'profile_picture_path' => $imagePath,
            ]);

            $staff = Staff::create([
                'user_id' => $user->id,
                'user_name' => $validated['user_name'],
                'department_id' => $validated['department_id'],
                'department_name' => $validated['department_name'],
                'full_name' => $validated['full_name'],
                'full_name_kh' => $validated['full_name_kh'] ?? null,
                'position' => $validated['position'],
                'email' => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? null,
                'address' => $validated['address'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
            ]);

            // Load user relationship and add image URL
            $staff->load('user');
            if ($staff->user && $staff->user->profile_picture_path) {
                $staff->user->profile_picture_url = url($staff->user->profile_picture_path);
            }

            DB::commit();

            return response()->json([
                'message' => 'Staff created successfully',
                'staff' => $staff,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete uploaded image if exists
            if ($imagePath && File::exists(public_path($imagePath))) {
                File::delete(public_path($imagePath));
            }

            Log::error('Failed to create staff', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to create staff',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update the specified staff member
     */
    public function update(Request $request, $id)
    {
        $staff = Staff::findOrFail($id);

        $validated = $request->validate([
            'email' => 'sometimes|email|unique:users,email,' . $staff->user_id,
            'password' => 'nullable|string|min:6',
            'role' => 'sometimes|in:admin,staff',
            'user_name' => 'sometimes|string|max:255',
            'department_id' => 'sometimes|exists:departments,id',
            'department_name' => 'sometimes|string|max:255',
            'full_name' => 'sometimes|string|max:255',
            'full_name_kh' => 'nullable|string|max:255',
            'position' => 'sometimes|string|max:255',
            'phone_number' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // Sanitize UTF-8
        $validated = $this->sanitizeInput($validated);

        DB::beginTransaction();

        try {
            $user = $staff->user;
            $oldImagePath = $user->profile_picture_path;
            $imagePath = null;

            // Handle profile image upload
            if ($request->hasFile('profile_image')) {
                // Create directory if not exists
                $uploadPath = public_path('uploads/profiles');
                if (!File::exists($uploadPath)) {
                    File::makeDirectory($uploadPath, 0755, true);
                }

                // Generate unique filename
                $image = $request->file('profile_image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                
                // Move to public directory
                $image->move($uploadPath, $filename);
                $imagePath = 'uploads/profiles/' . $filename;
                
                // Delete old image
                if ($oldImagePath && File::exists(public_path($oldImagePath))) {
                    File::delete(public_path($oldImagePath));
                }
            }

            // Update user
            $userUpdates = [];
            if (isset($validated['full_name'])) {
                $userUpdates['name'] = $validated['full_name'];
            }
            if (isset($validated['email'])) {
                $userUpdates['email'] = $validated['email'];
            }
            if (isset($validated['password'])) {
                $userUpdates['password'] = Hash::make($validated['password']);
            }
            if (isset($validated['role'])) {
                $userUpdates['role'] = $validated['role'];
            }
            if ($imagePath) {
                $userUpdates['profile_picture_path'] = $imagePath;
            }

            if (!empty($userUpdates)) {
                $user->update($userUpdates);
            }

            // Update staff
            $staffUpdates = array_intersect_key($validated, array_flip([
                'user_name', 'department_id', 'department_name', 'full_name',
                'full_name_kh', 'position', 'email', 'phone_number', 
                'address', 'gender', 'date_of_birth'
            ]));

            if (!empty($staffUpdates)) {
                $staff->update($staffUpdates);
            }

            // Refresh and add image URL
            $staff = $staff->fresh()->load('user');
            if ($staff->user && $staff->user->profile_picture_path) {
                $staff->user->profile_picture_url = url($staff->user->profile_picture_path);
            }

            DB::commit();

            return response()->json([
                'message' => 'Staff updated successfully',
                'staff' => $staff,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            // Delete uploaded image if exists
            if (isset($imagePath) && $imagePath && File::exists(public_path($imagePath))) {
                File::delete(public_path($imagePath));
            }

            Log::error('Failed to update staff', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to update staff',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified staff member
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $staff = Staff::findOrFail($id);
            $user = $staff->user;
            $imagePath = $user->profile_picture_path;

            $staff->delete();
            $user->delete();

            // Delete profile image if exists
            if ($imagePath && File::exists(public_path($imagePath))) {
                File::delete(public_path($imagePath));
            }

            DB::commit();

            return response()->json([
                'message' => 'Staff deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to delete staff', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to delete staff',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sanitize UTF-8 encoding for all string values
     */
    private function sanitizeInput(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitizeInput($value);
            }
        }
        return $data;
    }
}