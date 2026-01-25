<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuildingController extends Controller
{
    /**
     * GET /api/buildings
     * Get all buildings
     */
    public function index(Request $request)
    {
        try {
            $query = Building::withCount('rooms');

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search by name or code
            if ($request->search) {
                $query->where(function($q) use ($request) {
                    $q->where('building_name', 'like', '%' . $request->search . '%')
                      ->orWhere('building_code', 'like', '%' . $request->search . '%');
                });
            }

            $buildings = $query->orderBy('building_code')->get();

            return response()->json([
                'success' => true,
                'data' => $buildings,
                'total' => $buildings->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('BuildingController@index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load buildings',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/buildings/{id}
     * Get single building with rooms
     */
    public function show($id)
    {
        try {
            $building = Building::with(['rooms' => function($query) {
                $query->orderBy('room_number');
            }])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $building,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('BuildingController@show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Building not found',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 404);
        }
    }

    /**
     * POST /api/buildings
     * Create a new building
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'building_code' => 'required|string|max:10|unique:buildings,building_code',
                'building_name' => 'required|string|max:100',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'total_floors' => 'nullable|integer|min:1|max:50',
                'is_active' => 'nullable|boolean',
            ]);

            DB::beginTransaction();

            $building = Building::create($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Building created successfully',
                'data' => $building,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BuildingController@store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create building',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * PUT /api/buildings/{id}
     * Update a building
     */
    public function update(Request $request, $id)
    {
        try {
            $building = Building::findOrFail($id);

            $validated = $request->validate([
                'building_code' => 'sometimes|required|string|max:10|unique:buildings,building_code,' . $id,
                'building_name' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'total_floors' => 'nullable|integer|min:1|max:50',
                'is_active' => 'nullable|boolean',
            ]);

            DB::beginTransaction();

            $building->update($validated);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Building updated successfully',
                'data' => $building->fresh(),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BuildingController@update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update building',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * DELETE /api/buildings/{id}
     * Delete a building
     */
    public function destroy($id)
    {
        try {
            $building = Building::findOrFail($id);

            // Check if building has rooms
            if ($building->rooms()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete building with existing rooms. Please delete or reassign rooms first.',
                    'rooms_count' => $building->rooms()->count()
                ], 422);
            }

            DB::beginTransaction();

            $building->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Building deleted successfully'
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('BuildingController@destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete building',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/buildings/options
     * Get buildings for dropdown (active only)
     */
    public function options()
    {
        try {
            $buildings = Building::where('is_active', true)
                ->orderBy('building_code')
                ->get(['id', 'building_code', 'building_name'])
                ->map(function($building) {
                    return [
                        'id' => $building->id,
                        'label' => $building->building_code . ' - ' . $building->building_name,
                        'code' => $building->building_code,
                        'name' => $building->building_name,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $buildings,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('BuildingController@options error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load building options',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}