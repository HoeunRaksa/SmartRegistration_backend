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
        //
        Schema::dropIfExists('messages');
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('s_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('r_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->text('content')->nullable(); // text message

            $table->boolean('is_read')->default(false);

            $table->timestamps();

            $table->index(['s_id', 'r_id']);
        });
        
        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('messages')
                ->onDelete('cascade');

            $table->enum('type', ['image', 'file', 'audio']);
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->integer('file_size')->nullable();

            $table->timestamps();
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