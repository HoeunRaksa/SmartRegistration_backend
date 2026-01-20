<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class StudentCalendarController extends Controller
{
    public function index()
    {
        // return empty list for now (real later)
        return response()->json(['data' => []], 200);
    }
}
