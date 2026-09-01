<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody spent their experience on.
 *
 * XP used to be a scoreboard, then briefly a tier that happened to you. This
 * is the ledger that makes it a currency: an enchantment is chosen, paid for
 * once, and kept.
 *
 * The unique index is the whole guard. Buying is idempotent because a repeated
 * click, a double submit or a retried request must not charge twice for
 * something that is permanent anyway — there is no quantity here, only owned
 * or not.
 *
 * `cost` is written down rather than read back from config at spend time: the
 * price of an enchantment may change, and what somebody actually paid is a
 * fact about the past that a config edit must not rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xp_enchantments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->unsignedInteger('cost');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_enchantments');
    }
};
