<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CertificateRequestController extends Controller
{
    /**
     * Get all certificate requests for student
     */
    public function index(Request $request)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $requests = CertificateRequest::where('student_id', $student->id)
                ->orderByDesc('created_at')
                ->get();

            return response()->json(['data' => $requests], 200);
        } catch (\Throwable $e) {
            Log::error('CertificateRequestController@index error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load certificate requests'], 500);
        }
    }

    /**
     * Store new certificate request
     */
    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:enrollment,good_standing,completion,transcript_official',
            'remarks' => 'nullable|string|max:500',
        ]);

        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            
            $certificateRequest = CertificateRequest::create([
                'student_id' => $student->id,
                'type' => $request->type,
                'remarks' => $request->remarks,
                'status' => 'pending',
            ]);

            return response()->json([
                'message' => 'Certificate request submitted successfully',
                'data' => $certificateRequest
            ], 201);
        } catch (\Throwable $e) {
            Log::error('CertificateRequestController@store error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to submit certificate request'], 500);
        }
    }

    /**
     * Show certificate request details
     */
    public function show(Request $request, $id)
    {
        try {
            $student = Student::where('user_id', $request->user()->id)->firstOrFail();
            $certificateRequest = CertificateRequest::where('student_id', $student->id)
                ->findOrFail($id);

            return response()->json(['data' => $certificateRequest], 200);
        } catch (\Throwable $e) {
            Log::error('CertificateRequestController@show error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to load certificate request'], 500);
        }
    }
}
