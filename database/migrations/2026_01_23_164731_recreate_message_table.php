<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop in correct order (FK safety)
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
        //
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('s_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('r_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->enum('type', ['text', 'image', 'file', 'audio'])
                ->default('text');

            $table->text('content')->nullable(); // text message

            $table->string('file_path')->nullable(); // image, file, audio

            $table->boolean('is_read')->default(false);

            $table->timestamps();

            // performance
            $table->index(['s_id', 'r_id']);
            $table->index(['s_id', 'is_read']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};