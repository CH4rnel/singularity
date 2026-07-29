<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only XP ledger — the audit trail behind every level and streak.
 *
 * Every award carries a (source, reference) pair that identifies the real
 * thing it was paid for: an on-chain tx hash, a bridge request id, a proposal
 * vote row, a calendar day. The unique index on that pair is what makes
 * awarding idempotent, so the backfill command can rescan the whole history
 * as often as it likes without inflating anyone's score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xp_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // swap | liquidity | bridge | proposal | vote | comment | visit |
            // streak | quest | onchain_profile — see config/gamification.php.
            $table->string('source');
            // Natural key of the thing being paid for, unique within a source.
            // Empty string is never used: awards without a real subject use a
            // day stamp ("d:2026-07-29") so they cap at one per day.
            $table->string('reference');
            $table->integer('amount');
            $table->timestamp('created_at')->index();

            $table->unique(['user_id', 'source', 'reference']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_entries');
    }
};
