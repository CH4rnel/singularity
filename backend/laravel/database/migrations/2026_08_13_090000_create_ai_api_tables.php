<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the inference API.
 *
 * A key belongs to an address, not to an account: the wallet that proved it
 * holds the gate token is the whole identity here, exactly as in the $LAIN
 * holders' room. Nothing links a key to a user row, so having a Cyberia login
 * is not a condition of using the API.
 *
 * The key itself is not stored — only its SHA-256 and the visible prefix. A
 * leaked database therefore leaks who called and how much, never the
 * credentials to call again. The prefix exists so a holder can recognise their
 * own key in a list without the server being able to reconstruct it.
 *
 * The request log is metering, not content: model, provider, token counts and
 * outcome. Prompts and completions are never written here — this API stores no
 * conversation at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('address', 42)->index();
            $table->string('name', 60)->nullable();
            // What a listing shows, e.g. `sk-cyb-9f3a…`.
            $table->string('prefix', 24);
            $table->string('token_hash', 64)->unique();
            // Service keys, issued from the console for Cyberia's own daemons:
            // they answer to the quota like everyone else but not to the
            // holder gate, which no server-side service can satisfy.
            $table->boolean('gate_exempt')->default(false);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_api_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_api_key_id')->constrained()->cascadeOnDelete();
            // What was asked for, and what actually answered when a fallback
            // took over. Both, because the difference is the interesting part.
            $table->string('model', 64);
            $table->string('served_model', 64);
            $table->string('provider', 32);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedSmallInteger('status')->default(200);
            $table->boolean('streamed')->default(false);
            $table->timestamp('created_at')->useCurrent();

            // The daily quota counts this index, and so does the pruner.
            $table->index(['ai_api_key_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_api_requests');
        Schema::dropIfExists('ai_api_keys');
    }
};
