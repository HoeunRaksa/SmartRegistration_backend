<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\Building;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    /**
     * GET /api/rooms
     * Get all rooms with building info
     */
    public function index(Request $request)
    {
        try {
            $query = Room::with('building');

            // Filter by building
            if ($request->building_id) {
                $query->where('building_id', $request->building_id);
            }

            // Filter by room type
            if ($request->room_type) {
                $query->where('room_type', $request->room_type);
            }

            // Filter by availability
            if ($request->has('is_available')) {
                $query->where('is_available', $request->boolean('is_available'));
            }

            // Search by room number or name
            if ($request->search) {
                $query->where(function($q) use ($request) {
                    $q->where('room_number', 'like', '%' . $request->search . '%')
                      ->orWhere('room_name', 'like', '%' . $request->search . '%');
                });
            }

            $rooms = $query->orderBy('building_id')
                           ->orderBy('room_number')
                           ->get()
                           ->map(function($room) {
                               return $this->formatRoomResponse($room);
                           });

            return response()->json([
                'success' => true,
                'data' => $rooms,
                'total' => $rooms->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('RoomController@index error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load rooms',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/rooms/{id}
     * Get single room
     */
    public function show($id)
    {
        try {
            $room = Room::with('building')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->formatRoomResponse($room),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('RoomController@show error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Room not found',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 404);
        }
    }

    /**
     * POST /api/rooms
     * Create a new room
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'building_id' => 'required|integer|exists:buildings,id',
                'room_number' => 'required|string|max:20',
                'room_name' => 'nullable|string|max:100',
                'room_type' => 'required|in:classroom,lab,lecture_hall,seminar_room,computer_lab,library,office,other',
                'capacity' => 'nullable|integer|min:1|max:500',
                'floor' => 'nullable|integer|min:0|max:50',
                'facilities' => 'nullable|array',
                'is_available' => 'nullable|boolean',
            ]);

            // Check for duplicate room number in same building
            $exists = Room::where('building_id', $validated['building_id'])
                         ->where('room_number', $validated['room_number'])
                         ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Room number already exists in this building'
                ], 422);
            }

            DB::beginTransaction();

            $room = Room::create($validated);

            DB::commit();

            $room->load('building');

            return response()->json([
                'success' => true,
                'message' => 'Room created successfully',
                'data' => $this->formatRoomResponse($room),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('RoomController@store error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create room',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * PUT /api/rooms/{id}
     * Update a room
     */
    public function update(Request $request, $id)
    {
        try {
            $room = Room::findOrFail($id);

            $validated = $request->validate([
                'building_id' => 'sometimes|required|integer|exists:buildings,id',
                'room_number' => 'sometimes|required|string|max:20',
                'room_name' => 'nullable|string|max:100',
                'room_type' => 'sometimes|required|in:classroom,lab,lecture_hall,seminar_room,computer_lab,library,office,other',
                'capacity' => 'nullable|integer|min:1|max:500',
                'floor' => 'nullable|integer|min:0|max:50',
                'facilities' => 'nullable|array',
                'is_available' => 'nullable|boolean',
            ]);

            // Check for duplicate if room_number or building_id changed
            if (isset($validated['room_number']) || isset($validated['building_id'])) {
                $buildingId = $validated['building_id'] ?? $room->building_id;
                $roomNumber = $validated['room_number'] ?? $room->room_number;

                $exists = Room::where('building_id', $buildingId)
                             ->where('room_number', $roomNumber)
                             ->where('id', '!=', $id)
                             ->exists();

                if ($exists) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Room number already exists in this building'
                    ], 422);
                }
            }

            DB::beginTransaction();

            $room->update($validated);

            DB::commit();

            $room = $room->fresh('building');

            return response()->json([
                'success' => true,
                'message' => 'Room updated successfully',
                'data' => $this->formatRoomResponse($room),
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('RoomController@update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update room',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * DELETE /api/rooms/{id}
     * Delete a room
     */
    public function destroy($id)
    {
        try {
            $room = Room::findOrFail($id);

            // Check if room is used in schedules
            if ($room->schedules()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete room with existing schedules. Please reassign schedules first.',
                    'schedules_count' => $room->schedules()->count()
                ], 422);
            }

            // Check if room is used in sessions
            if ($room->sessions()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete room with existing sessions. Please reassign sessions first.',
                    'sessions_count' => $room->sessions()->count()
                ], 422);
            }

            DB::beginTransaction();

            $room->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Room deleted successfully'
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('RoomController@destroy error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete room',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/rooms/options
     * Get rooms for dropdown (available only)
     */
    public function options(Request $request)
    {
        try {
            $query = Room::with('building')
                         ->where('is_available', true);

            // Filter by building
            if ($request->building_id) {
                $query->where('building_id', $request->building_id);
            }

            // Filter by room type
            if ($request->room_type) {
                $query->where('room_type', $request->room_type);
            }

            $rooms = $query->orderBy('building_id')
                          ->orderBy('room_number')
                          ->get()
                          ->map(function($room) {
                              return [
                                  'id' => $room->id,
                                  'label' => $room->building->building_code . '-' . $room->room_number . 
                                           ($room->room_name ? ' (' . $room->room_name . ')' : ''),
                                  'building_id' => $room->building_id,
                                  'building_code' => $room->building->building_code,
                                  'room_number' => $room->room_number,
                                  'room_name' => $room->room_name,
                                  'room_type' => $room->room_type,
                                  'capacity' => $room->capacity,
                              ];
                          });

            return response()->json([
                'success' => true,
                'data' => $rooms,
            ], 200);

        } catch (\Throwable $e) {
            Log::error('RoomController@options error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load room options',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/rooms/by-building/{buildingId}
     * Get all rooms in a building
     */
    public function byBuilding($buildingId)
    {
        try {
            $building = Building::findOrFail($buildingId);

            $rooms = Room::where('building_id', $buildingId)
                        ->orderBy('room_number')
                        ->get()
                        ->map(function($room) {
                            return $this->formatRoomResponse($room);
                        });

            return response()->json([
                'success' => true,
                'building' => [
                    'id' => $building->id,
                    'code' => $building->building_code,
                    'name' => $building->building_name,
                ],
                'data' => $rooms,
                'total' => $rooms->count(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('RoomController@byBuilding error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load rooms',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * GET /api/rooms/check-availability
     * Check if room is available at specific time
     */
    public function checkAvailability(Request $request)
    {
        try {
            $validated = $request->validate([
                'room_id' => 'required|integer|exists:rooms,id',
                'day_of_week' => 'required|string',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'exclude_schedule_id' => 'nullable|integer',
            ]);

            $conflicts = DB::table('class_schedules')
                ->where('room_id', $validated['room_id'])
                ->where('day_of_week', $validated['day_of_week'])
                ->where(function($query) use ($validated) {
                    $query->where(function($q) use ($validated) {
                        $q->where('start_time', '<', $validated['end_time'])
                          ->where('end_time', '>', $validated['start_time']);
                    });
                })
                ->when($validated['exclude_schedule_id'] ?? null, function($query, $id) {
                    $query->where('id', '!=', $id);
                })
                ->get();

            $isAvailable = $conflicts->isEmpty();

            return response()->json([
                'success' => true,
                'is_available' => $isAvailable,
                'conflicts' => $conflicts,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('RoomController@checkAvailability error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check availability',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /* ========================================
       PRIVATE HELPER METHODS
       ======================================== */

    private function formatRoomResponse($room)
    {
        return [
            'id' => $room->id,
            'building_id' => $room->building_id,
            'building_code' => $room->building->building_code ?? null,
            'building_name' => $room->building->building_name ?? null,
            'room_number' => $room->room_number,
            'room_name' => $room->room_name,
            'full_name' => ($room->building->building_code ?? '') . '-' . $room->room_number,
            'room_type' => $room->room_type,
            'capacity' => $room->capacity,
            'floor' => $room->floor,
            'facilities' => $room->facilities,
            'is_available' => $room->is_available,
            'created_at' => $room->created_at,
            'updated_at' => $room->updated_at,
        ];
    }
}