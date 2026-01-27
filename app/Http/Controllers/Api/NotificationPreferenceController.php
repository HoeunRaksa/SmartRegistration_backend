<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationPreferenceController extends Controller
{
    /**
     * Get user's notification preferences
     * GET /api/notification-preferences
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // Get or create preferences (using JSON column or separate table)
            $preferences = DB::table('notification_preferences')
                ->where('user_id', $user->id)
                ->first();
            
            if (!$preferences) {
                // Default preferences
                $preferences = (object)[
                    'registration_emails' => true,
                    'payment_emails' => true,
                    'grade_emails' => true,
                    'assignment_emails' => true,
                ];
            }
            
            return response()->json([
                'success' => true,
                'data' => $preferences
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load preferences'
            ], 500);
        }
    }

    /**
     * Update notification preferences
     * PUT /api/notification-preferences
     */
    public function update(Request $request)
    {
        try {
            $validated = $request->validate([
                'registration_emails' => 'boolean',
                'payment_emails' => 'boolean',
                'grade_emails' => 'boolean',
                'assignment_emails' => 'boolean',
            ]);

            $user = $request->user();

            DB::table('notification_preferences')->updateOrInsert(
                ['user_id' => $user->id],
                array_merge($validated, [
                    'updated_at' => now()
                ])
            );

            return response()->json([
                'success' => true,
                'message' => 'Preferences updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update preferences'
            ], 500);
        }
    }
}
