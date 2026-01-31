<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid email or password',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken('api-token')->plainTextToken;

        // Create HttpOnly, Secure, SameSite=None cookie (backup/enhanced security)
        // cookie(name, value, minutes, path, domain, secure, httpOnly, raw, sameSite)
        $cookie = cookie('token', $token, 60 * 24 * 30, '/', null, true, true, false, 'None');

        return response()->json([
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
                'profile_picture_url' => $user->profile_picture_path
                    ? url($user->profile_picture_path)
                    : null,
            ],
            'token' => $token, // Also in response for immediate compatibility
        ])->withCookie($cookie);
    }

    public function logout(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete();
        }

        // Clear cookie
        $cookie = cookie()->forget('token');

        return response()->json([
            'message' => 'Logged out',
        ])->withCookie($cookie);
    }
}
