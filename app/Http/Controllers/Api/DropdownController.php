<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Department;
use App\Models\Major;

class DropdownController extends Controller
{
    // Get all departments
public function departments()
{
    $departments = Department::select('id', 'name', 'faculty')->get();

    return response()->json([
        'success' => true,
        'data' => $departments
    ]);
}



    // Get majors by department
    public function majors(Request $request, $department_id)
    {
        $majors = Major::where('department_id', $department_id)->get(['id', 'major_name']);
        return response()->json([
            'success' => true,
            'data' => $majors
        ]);
    }
}
