<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use App\Models\User;

class UserSettingsController extends Controller
{
    /**
     * Get current user profile
     */
    public function profile(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

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

        // Optionally revoke all tokens except current
        // $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

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
            'profile_picture' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048', // 2MB max
        ]);

        /** @var User $user */
        $user = $request->user();

        // Delete old profile picture if exists
        if ($user->profile_picture_path && Storage::disk('public')->exists($user->profile_picture_path)) {
            Storage::disk('public')->delete($user->profile_picture_path);
        }

        // Store new profile picture
        $path = $request->file('profile_picture')->store('profile_pictures', 'public');
        
        $user->profile_picture_path = 'storage/' . $path;
        $user->save();

        return response()->json([
            'message' => 'Profile picture uploaded successfully',
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'profile_picture_url' => url($user->profile_picture_path),
            ],
        ]);
    }

    /**
     * Delete profile picture
     */
    public function deleteProfilePicture(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->profile_picture_path && Storage::disk('public')->exists($user->profile_picture_path)) {
            Storage::disk('public')->delete($user->profile_picture_path);
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

        // Delete profile picture if exists
        if ($user->profile_picture_path && Storage::disk('public')->exists($user->profile_picture_path)) {
            Storage::disk('public')->delete($user->profile_picture_path);
        }

        // Revoke all tokens
        $user->tokens()->delete();

        // Delete user
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully',
        ]);
    }
}