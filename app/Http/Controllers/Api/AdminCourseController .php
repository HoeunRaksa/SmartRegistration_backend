<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminCourseController extends Controller
{
    /**
     * GET /api/admin/courses/options
     * Return courses for dropdown (id + display_name)
     */
    public function options(Request $request)
    {
        try {
            // Load relations used by Course::getDisplayNameAttribute()
            // (majorSubject -> subject, classGroup)
            $courses = Course::query()
                ->with([
                    'majorSubject.subject',
                    'classGroup',
                ])
                ->orderBy('academic_year')
                ->orderBy('semester')
                ->orderBy('id')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'display_name' => $c->display_name, // ✅ computed from model
                ]);

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('AdminCourseController@options error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load course options'], 500);
        }
    }
}
