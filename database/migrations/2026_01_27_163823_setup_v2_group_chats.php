<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Create conversations table
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable(); // For groups
            $table->enum('type', ['private', 'group'])->default('private');
            $table->foreignId('creator_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // 2. Create conversation_participants table
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        // 3. Add conversation_id and is_deleted to messages
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->boolean('is_deleted')->default(false)->after('is_read');
            $table->timestamp('deleted_at')->nullable()->after('is_deleted');
        });

        // 4. Migrate existing messages to conversations (Optional but good)
        // For each pair of (s_id, r_id) in existing messages, create a conversation if it doesn't exist.
        // This logic is easier in a separate Seeder or script, but we can do a simple one here.
        
        $messages = DB::table('messages')->whereNull('conversation_id')->get();
        $pairs = [];
        
        foreach ($messages as $msg) {
            $userIds = [$msg->s_id, $msg->r_id];
            sort($userIds);
            $key = implode('-', $userIds);
            
            if (!isset($pairs[$key])) {
                $convId = DB::table('conversations')->insertGetId([
                    'type' => 'private',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                DB::table('conversation_participants')->insert([
                    ['conversation_id' => $convId, 'user_id' => $msg->s_id, 'created_at' => now(), 'updated_at' => now()],
                    ['conversation_id' => $convId, 'user_id' => $msg->r_id, 'created_at' => now(), 'updated_at' => now()],
                ]);
                $pairs[$key] = $convId;
            }
            
            DB::table('messages')->where('id', $msg->id)->update(['conversation_id' => $pairs[$key]]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['conversation_id']);
            $table->dropColumn(['conversation_id', 'is_deleted', 'deleted_at']);
        });
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
