<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailCheckController extends Controller
{
    /**
     * Check if an email already exists in the system
     * Returns user info if found, allowing redirect to login
     */
    public function checkEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = strtolower(trim($request->email));

        // Check in users table
        $user = DB::table('users')
            ->where('email', $email)
            ->select('id', 'email', 'role', 'name')
            ->first();

        if ($user) {
            return response()->json([
                'exists' => true,
                'source' => 'user_account',
                'role' => $user->role,
                'name' => $user->name,
                'message' => 'This email is already registered. Please log in.',
            ]);
        }

        // Check in registrations table (pending registrations)
        $registration = DB::table('registrations')
            ->where('personal_email', $email)
            ->select('id', 'personal_email', 'first_name', 'last_name', 'payment_status')
            ->first();

        if ($registration) {
            $fullName = trim(($registration->first_name ?? '') . ' ' . ($registration->last_name ?? ''));
            
            return response()->json([
                'exists' => true,
                'source' => 'registration',
                'payment_status' => $registration->payment_status ?? 'PENDING',
                'name' => $fullName,
                'message' => 'This email has a pending registration. Please complete your payment or log in if you already have an account.',
            ]);
        }

        // Email not found
        return response()->json([
            'exists' => false,
            'message' => 'Email is available for registration.',
        ]);
    }
}
