<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalised per-user progress: the sum of xp_entries plus the streak
 * bookkeeping that can't be derived from the ledger alone. One row per user,
 * created lazily on the first tracked action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('level')->default(1);
            // Consecutive UTC days with at least one tracked action.
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_active_on')->nullable();
            $table->date('streak_started_on')->nullable();
            $table->timestamps();

            // Leaderboard ordering.
            $table->index(['xp', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_stats');
    }
};
