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
            $courses = Course::query()
                ->with(['majorSubject.subject', 'classGroup'])
                ->orderBy('academic_year')
                ->orderBy('semester')
                ->orderBy('id')
                ->get()
                ->map(function ($c) {
                    return [
                        'id' => $c->id,
                        'display_name' => $c->display_name,     // ✅ from $appends
                        'class_group_id' => $c->class_group_id,
                        'shift' => $c->classGroup?->shift,      // ✅ morning/afternoon/evening
                    ];
                });

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('AdminCourseController@options error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load course options'], 500);
        }
    }
}
