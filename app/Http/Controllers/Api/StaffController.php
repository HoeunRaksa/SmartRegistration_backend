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

class StaffController extends Controller
{
    /**
     * Sanitize UTF-8 encoding for all string values
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
                $imagePath = $request->file('profile_image')
                    ->store('profiles', 'public');
            }

            $user = User::create([
                'name' => $validated['full_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'staff',
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

            DB::commit();

            return response()->json([
                'message' => 'Staff created successfully',
                'staff' => $staff,
                'user' => $user,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
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
