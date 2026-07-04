<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency flag for the mint-model bridge-out burn: once the deposited
 * wrapper has been burned, a retry (bridge:relay) must not burn again — it just
 * re-runs the destination payout. Prevents "Failed to burn" on a request whose
 * burn already succeeded but whose payout failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->boolean('wrapper_burned')->default(false)->after('swept');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn('wrapper_burned');
        });
    }
};
