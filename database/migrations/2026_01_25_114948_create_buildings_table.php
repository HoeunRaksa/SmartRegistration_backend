<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('building_code', 10)->unique(); // e.g., "A", "B", "MAIN"
            $table->string('building_name', 100);          // e.g., "Main Building", "Engineering Block"
            $table->text('description')->nullable();
            $table->string('location')->nullable();        // e.g., "North Campus"
            $table->integer('total_floors')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Insert default buildings
        DB::table('buildings')->insert([
            [
                'building_code' => 'A',
                'building_name' => 'Main Building',
                'description' => 'Main academic building',
                'location' => 'Campus Center',
                'total_floors' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'building_code' => 'B',
                'building_name' => 'Engineering Building',
                'description' => 'Engineering and technology labs',
                'location' => 'East Campus',
                'total_floors' => 4,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'building_code' => 'C',
                'building_name' => 'Science Building',
                'description' => 'Science laboratories and classrooms',
                'location' => 'West Campus',
                'total_floors' => 3,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};