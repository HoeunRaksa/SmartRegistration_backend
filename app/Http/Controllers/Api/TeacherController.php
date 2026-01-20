<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;

class TeacherController extends Controller
{
    /**
     * GET /api/teachers?search=&per_page=10
     */
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $perPage = (int) $request->query('per_page', 10);

        $query = Teacher::with(['user:id,name,email,role,profile_picture_path', 'department:id,name'])
            ->latest();

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

        return response()->json($query->paginate($perPage));
    }

    /**
     * GET /api/teachers/{id}
     */
    public function show($id)
    {
        $teacher = Teacher::with(['user', 'department'])->findOrFail($id);

        // add full url for image (optional convenience)
        $teacher->user->profile_picture_url = $teacher->user->profile_picture_path
            ? asset($teacher->user->profile_picture_path)
            : null;

        return response()->json($teacher);
    }

    /**
     * POST /api/teachers
     * Content-Type: multipart/form-data
     * Fields:
     * - department_id, full_name, full_name_kh, gender, date_of_birth, address, phone_number
     * - name, email, password (for user)
     * - image (optional)
     *
     * Upload folder: public/uploads/teachers (or /profiles)
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
            'upload_to'      => ['nullable', 'in:teachers,profiles'], // optional: choose folder
        ]);

        // choose folder
        $folder = ($request->input('upload_to') === 'profiles')
            ? 'uploads/profiles'
            : 'uploads/teachers';

        // create user first
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'teacher',
            'profile_picture_path' => null,
        ]);

        // upload image into public/
        if ($request->hasFile('image')) {
            $path = $this->uploadPublicImage($request->file('image'), $folder);
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

        return response()->json([
            'message' => 'Teacher created successfully',
            'data' => Teacher::with(['user', 'department'])->find($teacher->id),
        ], 201);
    }

    /**
     * POST /api/teachers/{id}
     * multipart/form-data
     * - update teacher fields
     * - update user fields (name, email, password optional)
     * - image optional
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
            'email'          => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password'       => ['nullable', 'string', 'min:6'],

            // image
            'image'          => ['nullable', 'image', 'max:2048'],
            'upload_to'      => ['nullable', 'in:teachers,profiles'],
        ]);

        // choose folder
        $folder = ($request->input('upload_to') === 'profiles')
            ? 'uploads/profiles'
            : 'uploads/teachers';

        // update user
        $userUpdate = [
            'name' => $data['name'],
            'email' => $data['email'],
        ];
        if (!empty($data['password'])) {
            $userUpdate['password'] = Hash::make($data['password']);
        }

        // upload new image (delete old)
        if ($request->hasFile('image')) {
            $this->deletePublicFileIfExists($user->profile_picture_path);
            $path = $this->uploadPublicImage($request->file('image'), $folder);
            $userUpdate['profile_picture_path'] = $path;
        }

        $user->update($userUpdate);

        // update teacher
        $teacher->update([
            'department_id' => $data['department_id'],
            'full_name' => $data['full_name'],
            'full_name_kh' => $data['full_name_kh'] ?? null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'address' => $data['address'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
        ]);

        return response()->json([
            'message' => 'Teacher updated successfully',
            'data' => Teacher::with(['user', 'department'])->find($teacher->id),
        ]);
    }

    /**
     * DELETE /api/teachers/{id}
     * Deletes teacher, deletes image file, and (optionally) deletes linked user.
     */
    public function destroy($id)
    {
        $teacher = Teacher::with('user')->findOrFail($id);

        // delete image file
        if ($teacher->user) {
            $this->deletePublicFileIfExists($teacher->user->profile_picture_path);
        }

        // delete teacher first
        $teacher->delete();

        // delete user also (recommended)
        if ($teacher->user) {
            $teacher->user->delete();
        }

        return response()->json(['message' => 'Teacher deleted successfully']);
    }

    // ---------------- Helpers ----------------

    /**
     * Upload image into public/{folder} and return relative path like "uploads/teachers/abc.jpg"
     */
    private function uploadPublicImage($file, string $folder): string
    {
        $publicPath = public_path($folder);
        if (!File::exists($publicPath)) {
            File::makeDirectory($publicPath, 0755, true);
        }

        $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
        $file->move($publicPath, $filename);

        return $folder . '/' . $filename; // relative path saved in DB
    }

    /**
     * Delete file in public/ if exists. Accepts path like "uploads/teachers/abc.jpg"
     */
    private function deletePublicFileIfExists(?string $relativePath): void
    {
        if (!$relativePath) return;

        $fullPath = public_path($relativePath);
        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }
    }
}
