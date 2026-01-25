<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->onDelete('cascade');
            $table->string('room_number', 20);             // e.g., "101", "2A", "LAB-3"
            $table->string('room_name', 100)->nullable();  // e.g., "Computer Lab 1"
            $table->enum('room_type', [
                'classroom',
                'lab',
                'lecture_hall',
                'seminar_room',
                'computer_lab',
                'library',
                'office',
                'other'
            ])->default('classroom');
            $table->integer('capacity')->default(30);      // Number of seats
            $table->integer('floor')->nullable();
            $table->text('facilities')->nullable();        // JSON: ["projector", "whiteboard", "AC"]
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            // Unique: room_number per building
            $table->unique(['building_id', 'room_number']);
        });

        // Insert sample rooms
        DB::table('rooms')->insert([
            // Building A - Main Building
            ['building_id' => 1, 'room_number' => '101', 'room_name' => 'Classroom 101', 'room_type' => 'classroom', 'capacity' => 40, 'floor' => 1, 'facilities' => json_encode(['projector', 'whiteboard', 'AC']), 'created_at' => now(), 'updated_at' => now()],
            ['building_id' => 1, 'room_number' => '102', 'room_name' => 'Classroom 102', 'room_type' => 'classroom', 'capacity' => 35, 'floor' => 1, 'facilities' => json_encode(['projector', 'whiteboard']), 'created_at' => now(), 'updated_at' => now()],
            ['building_id' => 1, 'room_number' => '201', 'room_name' => 'Lecture Hall A', 'room_type' => 'lecture_hall', 'capacity' => 100, 'floor' => 2, 'facilities' => json_encode(['projector', 'sound_system', 'AC']), 'created_at' => now(), 'updated_at' => now()],
            
            // Building B - Engineering
            ['building_id' => 2, 'room_number' => 'LAB-1', 'room_name' => 'Computer Lab 1', 'room_type' => 'computer_lab', 'capacity' => 30, 'floor' => 1, 'facilities' => json_encode(['computers', 'projector', 'AC']), 'created_at' => now(), 'updated_at' => now()],
            ['building_id' => 2, 'room_number' => 'LAB-2', 'room_name' => 'Computer Lab 2', 'room_type' => 'computer_lab', 'capacity' => 25, 'floor' => 1, 'facilities' => json_encode(['computers', 'projector', 'AC']), 'created_at' => now(), 'updated_at' => now()],
            
            // Building C - Science
            ['building_id' => 3, 'room_number' => 'SCI-101', 'room_name' => 'Chemistry Lab', 'room_type' => 'lab', 'capacity' => 20, 'floor' => 1, 'facilities' => json_encode(['lab_equipment', 'fume_hood', 'safety_shower']), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};