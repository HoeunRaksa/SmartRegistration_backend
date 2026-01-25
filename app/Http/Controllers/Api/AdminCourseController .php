<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminCourseController extends Controller
{
    public function options(Request $request)
    {
        try {
            // ✅ Simpler query - only load what we need
            $courses = Course::query()
                ->select('id', 'course_code', 'course_name', 'class_group_id', 'academic_year', 'semester')
                ->with('classGroup:id,shift') // Only select needed fields
                ->orderBy('academic_year', 'desc')
                ->orderBy('semester', 'desc')
                ->get()
                ->map(function ($c) {
                    // ✅ Simple display name
                    $displayName = trim(($c->course_code ?? '') . ' - ' . ($c->course_name ?? ''));
                    if ($displayName === '-' || $displayName === '') {
                        $displayName = 'Course #' . $c->id;
                    }

                    return [
                        'id' => $c->id,
                        'display_name' => $displayName,
                        'class_group_id' => $c->class_group_id,
                        'shift' => $c->classGroup->shift ?? null,
                    ];
                });

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('AdminCourseController@options error: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'message' => 'Failed to load course options',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ], 500);
        }
    }
}