<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every drip the gas station has served, as this server saw it.
 *
 * The contract already keeps the accounting that matters — how much was spent,
 * by whom, when — and it is the authority on all of it. This table exists for
 * the part the chain cannot know: which request asked, from roughly where, and
 * on what grounds it was granted. That is what a daily per-IP quota is counted
 * from, and it is counted from rows rather than from the cache so that clearing
 * the cache does not hand anyone a fresh allowance.
 *
 * The address is public by nature. The caller's IP is not, so only a hash of it
 * is kept: enough to recognise the same asker twice, useless for anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gas_sponsorships', function (Blueprint $table) {
            $table->id();
            $table->string('address', 42)->index();
            // Wei as a string: a 256-bit amount does not fit in a bigint, and
            // this one is only ever displayed or summed with bcmath.
            $table->string('amount_wei', 78)->default('0');
            $table->string('tx_hash', 66)->nullable()->unique();
            // Why it was granted — 'tokens', 'nft' or 'account'. Kept so a
            // change in the gate can be measured against what it used to let in.
            $table->string('grounds', 24)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            // The two quota reads: this IP today, and everyone today.
            $table->index(['ip_hash', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gas_sponsorships');
    }
};
