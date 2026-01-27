<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class TeacherController extends Controller
{
    /**
     * GET /api/teachers?search=&per_page=10
     */
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $perPage = (int) $request->query('per_page', 10);

        $query = Teacher::with([
                'user:id,name,email,role,profile_picture_path',
                'department:id,name'
            ])
            ->latest('id');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_kh', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                           ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('department', function ($dq) use ($search) {
                        $dq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $result = $query->paginate($perPage);

        // add profile_picture_url for each row (convenience)
        $result->getCollection()->transform(function ($t) {
            if ($t->user) {
                $t->user->profile_picture_url = $t->user->profile_picture_path
                    ? asset($t->user->profile_picture_path)
                    : null;
            }
            return $t;
        });

        return response()->json($result);
    }

    /**
     * GET /api/teachers/{id}
     */
    public function show($id)
    {
        $teacher = Teacher::with(['user', 'department'])->findOrFail($id);

        if ($teacher->user) {
            $teacher->user->profile_picture_url = $teacher->user->profile_picture_path
                ? asset($teacher->user->profile_picture_path)
                : null;
        }

        return response()->json($teacher);
    }

    /**
     * POST /api/teachers
     * multipart/form-data
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            // teacher
            'department_id'  => ['required', 'exists:departments,id'],
            'full_name'      => ['required', 'string', 'max:255'],
            'full_name_kh'   => ['nullable', 'string', 'max:255'],
            'gender'         => ['nullable', 'string', 'max:20'],
            'date_of_birth'  => ['nullable', 'date'],
            'address'        => ['nullable', 'string', 'max:255'],
            'phone_number'   => ['nullable', 'string', 'max:30'],

            // user
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'       => ['required', 'string', 'min:6'],

            // image
            'image'          => ['nullable', 'image', 'max:2048'],
        ]);

        DB::beginTransaction();
        try {
            // create user first
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => 'teacher',
                'profile_picture_path' => null,
            ]);

            // upload image into public/uploads/teachers (same style)
            if ($request->hasFile('image')) {
                $path = $this->uploadTeacherImage($request->file('image'));
                $user->update(['profile_picture_path' => $path]);
            }

            // create teacher
            $teacher = Teacher::create([
                'user_id' => $user->id,
                'department_id' => $data['department_id'],
                'full_name' => $data['full_name'],
                'full_name_kh' => $data['full_name_kh'] ?? null,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'address' => $data['address'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
            ]);

            DB::commit();

            $teacher = Teacher::with(['user', 'department'])->find($teacher->id);
            if ($teacher && $teacher->user) {
                $teacher->user->profile_picture_url = $teacher->user->profile_picture_path
                    ? asset($teacher->user->profile_picture_path)
                    : null;
            }

            return response()->json([
                'message' => 'Teacher created successfully',
                'data' => $teacher,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TeacherController@store error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create teacher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/teachers/{id}
     * multipart/form-data (FormData friendly)
     *
     * ✅ password is OPTIONAL here (admin can update info without password)
     * ✅ image optional (replace + delete old)
     */
    public function update(Request $request, $id)
    {
        $teacher = Teacher::with('user')->findOrFail($id);
        $user = $teacher->user;

        $data = $request->validate([
            // teacher
            'department_id'  => ['required', 'exists:departments,id'],
            'full_name'      => ['required', 'string', 'max:255'],
            'full_name_kh'   => ['nullable', 'string', 'max:255'],
            'gender'         => ['nullable', 'string', 'max:20'],
            'date_of_birth'  => ['nullable', 'date'],
            'address'        => ['nullable', 'string', 'max:255'],
            'phone_number'   => ['nullable', 'string', 'max:30'],

            // user
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['required', 'email', 'max:255', 'unique:users,email,' . ($user?->id ?? 'NULL')],

            // ✅ OPTIONAL: allow password change here if you want
            'password'       => ['nullable', 'string', 'min:6'],

            // image
            'image'          => ['nullable', 'image', 'max:2048'],
        ]);

        DB::beginTransaction();
        try {
            // update user fields
            if ($user) {
                $userUpdate = [
                    'name' => $data['name'],
                    'email' => $data['email'],
                ];

                if (!empty($data['password'])) {
                    $userUpdate['password'] = Hash::make($data['password']);
                    // revoke tokens (force re-login) if you want
                    $user->tokens()->delete();
                }

                // upload new image (delete old)
                if ($request->hasFile('image')) {
                    $this->deletePublicFileIfExists($user->profile_picture_path);
                    $path = $this->uploadTeacherImage($request->file('image'));
                    $userUpdate['profile_picture_path'] = $path;
                }

                $user->update($userUpdate);
            }

            // update teacher fields
            $teacher->update([
                'department_id' => $data['department_id'],
                'full_name' => $data['full_name'],
                'full_name_kh' => $data['full_name_kh'] ?? null,
                'gender' => $data['gender'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'address' => $data['address'] ?? null,
                'phone_number' => $data['phone_number'] ?? null,
            ]);

            DB::commit();

            $teacher = Teacher::with(['user', 'department'])->find($teacher->id);
            if ($teacher && $teacher->user) {
                $teacher->user->profile_picture_url = $teacher->user->profile_picture_path
                    ? asset($teacher->user->profile_picture_path)
                    : null;
            }

            return response()->json([
                'message' => 'Teacher updated successfully',
                'data' => $teacher,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('TeacherController@update error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update teacher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/teachers/{id}/reset-password
     * Like Student resetPassword style
     */
    public function resetPassword(Request $request, $id)
    {
        $validated = $request->validate([
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);

        DB::beginTransaction();
        try {
            $teacher = Teacher::with('user')->findOrFail($id);

            if (!$teacher->user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Teacher has no associated user account'
                ], 404);
            }

            $user = $teacher->user;
            $user->password = Hash::make($validated['new_password']);
            $user->save();

            // revoke tokens to force re-login
            $user->tokens()->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully. Teacher must login with new password.'
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to reset password',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * DELETE /api/teachers/{id}
     */
    public function destroy($id)
    {
        $teacher = Teacher::with('user')->findOrFail($id);

        DB::beginTransaction();
        try {
            if ($teacher->user) {
                $this->deletePublicFileIfExists($teacher->user->profile_picture_path);
            }

            $user = $teacher->user;

            $teacher->delete();

            if ($user) {
                $user->tokens()->delete();
                $user->delete();
            }

            DB::commit();

            return response()->json(['message' => 'Teacher deleted successfully']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete teacher',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ---------------- Helpers ----------------

    /**
     * ✅ EXACT STYLE you requested:
     * $uploadPath = public_path('uploads/teachers')
     * Save DB path: uploads/teachers/xxx.jpg
     */
    private function uploadTeacherImage($file): string
    {
        $uploadPath = public_path('uploads/teachers');
        if (!File::exists($uploadPath)) {
            File::makeDirectory($uploadPath, 0755, true);
        }

        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($uploadPath, $filename);

        return 'uploads/teachers/' . $filename;
    }

    private function deletePublicFileIfExists(?string $relativePath): void
    {
        if (!$relativePath) return;

        $fullPath = public_path($relativePath);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
