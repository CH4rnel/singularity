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
        Schema::table('arena_seasons', function (Blueprint $table) {
            $table->timestamp('last_tick_at')->nullable();
            $table->string('automation_error', 80)->nullable();
        });
        Schema::create('arena_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_season_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('slot');
            $table->timestamp('decision_at');
            $table->timestamp('fill_due_at');
            $table->string('status', 24);
            $table->string('error_code', 80)->nullable();
            $table->json('record');
            $table->string('commit_hash', 64)->nullable();
            $table->unsignedInteger('reserved_cents')->default(0);
            $table->unsignedInteger('reserved_calls')->default(0);
            $table->foreignId('arena_round_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['arena_season_id', 'slot']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arena_cycles');
        Schema::table('arena_seasons', function (Blueprint $table) {
            $table->dropColumn(['last_tick_at', 'automation_error']);
        });
    }
};
