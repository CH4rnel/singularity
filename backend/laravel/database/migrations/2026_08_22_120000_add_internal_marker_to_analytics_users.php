<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which installations are ours.
 *
 * The people who build a wallet are also the people who use it hardest, and on
 * a product this young they are most of the traffic — which makes every rate on
 * the dashboard a description of the operator's testing habits rather than of
 * the market. The fix is not to delete their rows: an operator's install is a
 * real install, and their sessions are the ones that catch bugs. It is to be
 * able to *say which ones they are*, and to leave them out of the numbers that
 * are supposed to be about strangers.
 *
 * A column rather than a join against a config list, for the same reason the
 * funnel milestones are columns: the answer is stamped once, at the moment
 * something proves it, and it survives that config list changing. It is also
 * the only place a judgement can be recorded that no list could hold — an
 * operator testing from an address nobody registered anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_users', function (Blueprint $table) {
            // When this installation was recognised as ours. Null is the
            // ordinary case and the one every default query keeps.
            $table->timestamp('internal_at')->nullable()->index();
            // How it was recognised: 'address' (an address on the internal
            // list turned up on it) or 'manual' (an operator said so).
            $table->string('internal_reason', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_users', function (Blueprint $table) {
            $table->dropColumn(['internal_at', 'internal_reason']);
        });
    }
};
