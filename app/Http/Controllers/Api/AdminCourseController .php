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
            $courses = Course::with([
                    'majorSubject.subject',
                    'classGroup'
                ])
                ->orderBy('academic_year', 'desc')
                ->orderBy('semester', 'desc')
                ->orderBy('id')
                ->get()
                ->map(function ($c) {
                    return [
                        'id' => $c->id,
                        'display_name' => $c->display_name,
                        'class_group_id' => $c->class_group_id,
                        'shift' => $c->classGroup?->shift ?? null,
                    ];
                });

            return response()->json(['data' => $courses], 200);
        } catch (\Throwable $e) {
            Log::error('AdminCourseController@options error: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'message' => 'Failed to load course options',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }
}