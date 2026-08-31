<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable markers for the three moments a bridge request can crash between.
 *
 * `source_verified_at` is what turns a claim into an obligation: before it, a
 * failed request releases its reservation; after it, the user's money is gone
 * and the promise stands whatever happens next.
 *
 * `payout_broadcast_at` (with the hash already in destination_tx_hash) is what
 * makes a payout un-repeatable. A process that dies between the broadcast and
 * the DB write is the expensive crash window, so the hash is written the
 * moment the relay script prints it, before any receipt is waited for.
 *
 * `payout_confirmed_at` separates "on the wire" from "mined", so a retry knows
 * whether to reconcile a hash or to move on to the burn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->timestamp('source_verified_at')->nullable()->after('wrapper_burned');
            $table->timestamp('payout_broadcast_at')->nullable()->after('source_verified_at');
            $table->timestamp('payout_confirmed_at')->nullable()->after('payout_broadcast_at');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn(['source_verified_at', 'payout_broadcast_at', 'payout_confirmed_at']);
        });
    }
};
