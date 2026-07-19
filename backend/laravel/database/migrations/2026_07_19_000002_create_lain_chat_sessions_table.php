<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lain_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // updated_at doubles as "last activity" and orders the session list.
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::table('lain_chat_messages', function (Blueprint $table) {
            $table->foreignId('session_id')
                ->nullable()
                ->after('user_id')
                ->constrained('lain_chat_sessions')
                ->cascadeOnDelete();
        });

        // Backfill: the previous model separated conversations with 'reset'
        // boundary rows. Split each user's log on them into real sessions,
        // then drop the boundary rows.
        $rows = DB::table('lain_chat_messages')->orderBy('user_id')->orderBy('id')->get();
        $currentUser = null;
        $sessionId = null;

        foreach ($rows as $row) {
            if ($row->user_id !== $currentUser) {
                $currentUser = $row->user_id;
                $sessionId = null;
            }

            if ($row->role === 'reset') {
                $sessionId = null;
                DB::table('lain_chat_messages')->where('id', $row->id)->delete();

                continue;
            }

            if ($sessionId === null) {
                $sessionId = DB::table('lain_chat_sessions')->insertGetId([
                    'user_id' => $row->user_id,
                    'title' => Str::limit(trim($row->content) ?: 'Conversation', 48),
                    'created_at' => $row->created_at,
                    'updated_at' => $row->created_at,
                ]);
            }

            DB::table('lain_chat_messages')->where('id', $row->id)->update(['session_id' => $sessionId]);
            DB::table('lain_chat_sessions')->where('id', $sessionId)->update(['updated_at' => $row->created_at]);
        }
    }

    public function down(): void
    {
        Schema::table('lain_chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('session_id');
        });
        Schema::dropIfExists('lain_chat_sessions');
    }
};
