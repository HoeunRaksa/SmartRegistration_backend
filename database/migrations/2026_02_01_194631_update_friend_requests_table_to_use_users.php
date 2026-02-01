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
        // 1. Drop the original foreign keys first (MySQL requirement)
        Schema::table('friend_requests', function (Blueprint $table) {
            // We use array syntax which Laravel resolves to standard names
            $table->dropForeign(['sender_id']);
            $table->dropForeign(['receiver_id']);
        });

        // 2. Drop the unique index
        Schema::table('friend_requests', function (Blueprint $table) {
            $table->dropUnique(['sender_id', 'receiver_id']);
        });

        // 3. Migrate existing data if any (mapping student_id to user_id)
        $requests = DB::table('friend_requests')->get();
        foreach ($requests as $req) {
            $senderUser = DB::table('students')->where('id', $req->sender_id)->first();
            $receiverUser = DB::table('students')->where('id', $req->receiver_id)->first();
            
            if ($senderUser && $receiverUser) {
                DB::table('friend_requests')
                    ->where('id', $req->id)
                    ->update([
                        'sender_id' => $senderUser->user_id,
                        'receiver_id' => $receiverUser->user_id
                    ]);
            } else {
                DB::table('friend_requests')->where('id', $req->id)->delete();
            }
        }

        // 4. Update the constraints to point to users table
        Schema::table('friend_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('sender_id')->change();
            $table->unsignedBigInteger('receiver_id')->change();
            
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
            
            $table->unique(['sender_id', 'receiver_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('friend_requests', function (Blueprint $table) {
            $table->dropUnique(['sender_id', 'receiver_id']);
            $table->dropForeign(['sender_id']);
            $table->dropForeign(['receiver_id']);
        });
        
        DB::table('friend_requests')->truncate();
        
        Schema::table('friend_requests', function (Blueprint $table) {
            $table->foreign('sender_id')->references('id')->on('students')->onDelete('cascade');
            $table->foreign('receiver_id')->references('id')->on('students')->onDelete('cascade');
            $table->unique(['sender_id', 'receiver_id']);
        });
    }
};
