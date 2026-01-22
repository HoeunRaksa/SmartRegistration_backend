<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Major;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MajorCapacityController extends Controller
{
    // GET /api/majors/{id}/capacity?academic_year=2026-2027
    public function show($id, Request $request)
    {
        $major = Major::findOrFail($id);

        $academicYear = (string) $request->query('academic_year', '');
        if ($academicYear === '') {
            return response()->json([
                'success' => false,
                'message' => 'academic_year is required (e.g. 2026-2027)'
            ], 422);
        }

        $quota = DB::table('major_quotas')
            ->where('major_id', $major->id)
            ->where('academic_year', $academicYear)
            ->first();

        $used = (int) DB::table('registrations')
            ->where('major_id', $major->id)
            ->where('academic_year', $academicYear)
            ->count();

        // No quota row => unlimited + always open (common)
        if (!$quota) {
            return response()->json([
                'success' => true,
                'major_id' => (int) $major->id,
                'academic_year' => $academicYear,

                'limited' => false,
                'limit' => null,
                'used' => $used,
                'remaining' => null,

                'opens_at' => null,
                'closes_at' => null,
                'is_open_now' => true,

                'available' => true,
            ]);
        }

        $limit = (int) $quota->limit;
        $remaining = max(0, $limit - $used);

        $now = now();
        $opensAt = $quota->opens_at ? \Carbon\Carbon::parse($quota->opens_at) : null;
        $closesAt = $quota->closes_at ? \Carbon\Carbon::parse($quota->closes_at) : null;

        $isOpenNow = true;
        if ($opensAt && $now->lt($opensAt)) $isOpenNow = false;
        if ($closesAt && $now->gt($closesAt)) $isOpenNow = false;

        $available = $isOpenNow && ($used < $limit);

        return response()->json([
            'success' => true,
            'major_id' => (int) $major->id,
            'academic_year' => $academicYear,

            'limited' => true,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,

            'opens_at' => $quota->opens_at,
            'closes_at' => $quota->closes_at,
            'is_open_now' => $isOpenNow,

            'available' => $available,
        ]);
    }
}
