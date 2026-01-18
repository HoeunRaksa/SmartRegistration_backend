<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Registration;
use Barryvdh\DomPDF\Facade\Pdf;

class RegistrationReportController extends Controller
{
    /**
     * Generate registration report based on filters
     */
    public function generate(Request $request)
    {
        $query = Registration::with(['department', 'major', 'student']);

        // Apply filters
        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('major_id') && $request->major_id) {
            $query->where('major_id', $request->major_id);
        }

        if ($request->has('payment_status') && $request->payment_status) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('academic_year') && $request->academic_year) {
            $query->where('academic_year', $request->academic_year);
        }

        if ($request->has('shift') && $request->shift) {
            $query->where('shift', $request->shift);
        }

        if ($request->has('gender') && $request->gender) {
            $query->where('gender', $request->gender);
        }

        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Get results
        $registrations = $query->orderBy('created_at', 'desc')->get();

        // Calculate statistics
        $stats = [
            'total_registrations' => $registrations->count(),
            'total_male' => $registrations->where('gender', 'Male')->count(),
            'total_female' => $registrations->where('gender', 'Female')->count(),
            'payment_pending' => $registrations->where('payment_status', 'PENDING')->count(),
            'payment_completed' => $registrations->where('payment_status', 'COMPLETED')->count(),
            'total_amount' => $registrations->sum('payment_amount'),
            'paid_amount' => $registrations->where('payment_status', 'COMPLETED')->sum('payment_amount'),
        ];

        // Group by department
        $by_department = $registrations->groupBy('department.name')->map(function ($items) {
            return [
                'count' => $items->count(),
                'male' => $items->where('gender', 'Male')->count(),
                'female' => $items->where('gender', 'Female')->count(),
            ];
        });

        // Group by major
        $by_major = $registrations->groupBy('major.major_name')->map(function ($items) {
            return [
                'count' => $items->count(),
                'male' => $items->where('gender', 'Male')->count(),
                'female' => $items->where('gender', 'Female')->count(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'registrations' => $registrations,
                'statistics' => $stats,
                'by_department' => $by_department,
                'by_major' => $by_major,
                'filters' => $request->all(),
            ]
        ]);
    }

    /**
     * Export registration report to PDF
     */
    public function exportPdf(Request $request)
    {
        $query = Registration::with(['department', 'major', 'student']);

        // Apply same filters as generate
        if ($request->has('department_id') && $request->department_id) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('major_id') && $request->major_id) {
            $query->where('major_id', $request->major_id);
        }

        if ($request->has('payment_status') && $request->payment_status) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->has('academic_year') && $request->academic_year) {
            $query->where('academic_year', $request->academic_year);
        }

        if ($request->has('shift') && $request->shift) {
            $query->where('shift', $request->shift);
        }

        if ($request->has('gender') && $request->gender) {
            $query->where('gender', $request->gender);
        }

        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $registrations = $query->orderBy('created_at', 'desc')->get();

        // Calculate statistics
        $stats = [
            'total_registrations' => $registrations->count(),
            'total_male' => $registrations->where('gender', 'Male')->count(),
            'total_female' => $registrations->where('gender', 'Female')->count(),
            'payment_pending' => $registrations->where('payment_status', 'PENDING')->count(),
            'payment_completed' => $registrations->where('payment_status', 'COMPLETED')->count(),
            'total_amount' => $registrations->sum('payment_amount'),
            'paid_amount' => $registrations->where('payment_status', 'COMPLETED')->sum('payment_amount'),
        ];

        // Prepare data for PDF
        $data = [
            'registrations' => $registrations,
            'stats' => $stats,
            'filters' => $request->all(),
            'generated_date' => now()->format('F d, Y H:i:s'),
        ];

        // Generate PDF
        $pdf = Pdf::loadView('reports.registration', $data);
        $pdf->setPaper('a4', 'landscape');

        // Generate filename
        $filename = 'registration_report_' . now()->format('YmdHis') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * Get report summary statistics
     */
    public function summary(Request $request)
    {
        $query = Registration::query();

        // Apply filters if provided
        if ($request->has('academic_year') && $request->academic_year) {
            $query->where('academic_year', $request->academic_year);
        }

        $registrations = $query->get();

        $summary = [
            'total_registrations' => $registrations->count(),
            'by_gender' => [
                'male' => $registrations->where('gender', 'Male')->count(),
                'female' => $registrations->where('gender', 'Female')->count(),
            ],
            'by_payment_status' => [
                'pending' => $registrations->where('payment_status', 'PENDING')->count(),
                'completed' => $registrations->where('payment_status', 'COMPLETED')->count(),
                'failed' => $registrations->where('payment_status', 'FAILED')->count(),
            ],
            'by_shift' => $registrations->groupBy('shift')->map->count(),
            'financial' => [
                'total_amount' => $registrations->sum('payment_amount'),
                'paid_amount' => $registrations->where('payment_status', 'COMPLETED')->sum('payment_amount'),
                'pending_amount' => $registrations->where('payment_status', 'PENDING')->sum('payment_amount'),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}