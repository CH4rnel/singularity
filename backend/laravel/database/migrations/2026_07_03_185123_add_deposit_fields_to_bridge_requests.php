<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request one-time deposit addresses (Yenten). A request is created at
 * "prepare" time with a unique deposit_address and its recipient committed,
 * before any deposit exists — so source_tx_hash becomes nullable (filled at
 * "claim"). swept tracks whether the deposited coins have been consolidated
 * into the central relayer wallet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->string('deposit_address')->nullable()->after('recipient_address');
            $table->boolean('swept')->default(false)->after('deposit_address');
        });

        // source_tx_hash and sender_address are null between prepare and claim.
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->string('source_tx_hash')->nullable()->change();
            $table->string('sender_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn(['deposit_address', 'swept']);
        });

        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->string('source_tx_hash')->nullable(false)->change();
            $table->string('sender_address')->nullable(false)->change();
        });
    }
};
