<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rules\Password;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserSettingsController extends Controller
{
    /**
     * Get current user profile
     */
    public function profile(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'profile_picture_url' => $user->profile_picture_path
                    ? url($user->profile_picture_path)
                    : null,
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * Update user name
     */
    public function updateName(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->name = $request->name;
        $user->save();

        return response()->json([
            'message' => 'Name updated successfully',
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'profile_picture_url' => $user->profile_picture_path
                    ? url($user->profile_picture_path)
                    : null,
            ],
        ]);
    }

    /**
     * Update user email
     */
    public function updateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:users,email,' . $request->user()->id,
            'current_password' => 'required',
        ]);

        /** @var User $user */
        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);
        }

        $user->email = $request->email;
        $user->email_verified_at = null; // Reset email verification
        $user->save();

        return response()->json([
            'message' => 'Email updated successfully. Please verify your new email.',
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'profile_picture_url' => $user->profile_picture_path
                    ? url($user->profile_picture_path)
                    : null,
            ],
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => ['required', 'confirmed', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);
        }

        // Check if new password is different from current
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'message' => 'New password must be different from current password',
            ], 422);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * Upload profile picture
     */
    public function uploadProfilePicture(Request $request)
    {
        $request->validate([
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048', // 2MB max
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $oldImagePath = $user->profile_picture_path;

            // Create directory if not exists
            $uploadPath = public_path('uploads/profiles');
            if (!File::exists($uploadPath)) {
                File::makeDirectory($uploadPath, 0755, true);
            }

            // Generate unique filename
            $image = $request->file('profile_picture');
            $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
            
            // Move to public directory
            $image->move($uploadPath, $filename);
            $imagePath = 'uploads/profiles/' . $filename;

            // Update user
            $user->profile_picture_path = $imagePath;
            $user->save();

            // Delete old image
            if ($oldImagePath && File::exists(public_path($oldImagePath))) {
                File::delete(public_path($oldImagePath));
            }

            return response()->json([
                'message' => 'Profile picture uploaded successfully',
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                    'profile_picture_url' => url($imagePath),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to upload profile picture', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Failed to upload profile picture',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete profile picture
     */
    public function deleteProfilePicture(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $imagePath = $user->profile_picture_path;

            // Delete image file from public folder
            if ($imagePath && File::exists(public_path($imagePath))) {
                File::delete(public_path($imagePath));
            }

            $user->profile_picture_path = null;
            $user->save();

            return response()->json([
                'message' => 'Profile picture deleted successfully',
                'user' => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'role'  => $user->role,
                    'profile_picture_url' => null,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete profile picture', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to delete profile picture',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete account
     */
    public function deleteAccount(Request $request)
    {
        $request->validate([
            'password' => 'required',
        ]);

        /** @var User $user */
        $user = $request->user();

        // Verify password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password is incorrect',
            ], 422);
        }

        try {
            // Delete profile picture if exists
            if ($user->profile_picture_path && File::exists(public_path($user->profile_picture_path))) {
                File::delete(public_path($user->profile_picture_path));
            }

            // Revoke all tokens
            $user->tokens()->delete();

            // Delete user
            $user->delete();

            return response()->json([
                'message' => 'Account deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete account', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to delete account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}