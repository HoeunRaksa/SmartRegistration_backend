<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    public function store(Request $request)
    {
        Log::info('Request Data:', ['data' => $request->all()]);

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'required|string',
            'date_of_birth' => 'required|date',
            'personal_email' => 'nullable|email',
            'phone_number' => 'nullable|string|max:20',
            'department_id' => 'required|exists:departments,id',
            'major_id' => 'required|exists:majors,id',
        ]);

        // Check for duplicate
        $exists = DB::table('registrations')
            ->where('personal_email', $validated['personal_email'])
            ->orWhere('phone_number', $validated['phone_number'] ?? '')
            ->first();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Registration already exists for this email or phone number.'
            ], 409);
        }
        $data = array_merge($validated, [
            'full_name_kh' => $request->input('full_name_kh', $validated['first_name'] . ' ' . $validated['last_name']),
            'full_name_en' => $request->input('full_name_en', $validated['first_name'] . ' ' . $validated['last_name']),
            'faculty' => $request->input('faculty', ''),
            'shift' => $request->input('shift', 'Morning'),
            'batch' => $request->input('batch', ''),
            'academic_year' => $request->input('academic_year', ''),
            'high_school_name' => $request->input('high_school_name', ''),
            'graduation_year' => $request->input('graduation_year', ''),
            'grade12_result' => $request->input('grade12_result', ''),
            'address' => $request->input('address', ''),
            'current_address' => $request->input('current_address', ''),
            'father_name' => $request->input('father_name', ''),
            'fathers_job' => $request->input('fathers_job', ''),
            'mother_name' => $request->input('mother_name', ''),
            'mothers_job' => $request->input('mothers_job', ''),
            'guardian_name' => $request->input('guardian_name', ''),
            'guardian_phone' => $request->input('guardian_phone', ''),
            'emergency_contact_name' => $request->input('emergency_contact_name', ''),
            'emergency_contact_phone' => $request->input('emergency_contact_phone', ''),
            'profile_picture_path' => $request->input('profile_picture_path', null),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('registrations')->insert($data);
        return response()->json([
            'success' => true,
            'message' => 'Registration created successfully',
            'data' => $data
        ], 201);
    }
}
