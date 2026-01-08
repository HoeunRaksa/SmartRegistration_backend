<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
  public function login(Request $request)
{
    $request->validate([
        'email'    => 'required|email',
        'password' => 'required'
    ]);

    if (!Auth::attempt($request->only('email','password'))) {
        return response()->json([
            'message' => 'Invalid email or password'
        ], 401);
    }

    $user = Auth::user();
    $token = $user->createToken('api-token')->plainTextToken;

    $userData = [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'profile_picture_url' => $user->profile_picture_path 
            ? url('storage/'.$user->profile_picture_path) 
            : null,
    ];

    return response()->json([
        'user'  => $userData,
        'token' => $token
    ]);
}

    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out'
        ]);
    }
}
