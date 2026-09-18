<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card purchases handed to an on-ramp provider.
 *
 * Amounts are strings for the usual reason — a decimal column with the wrong
 * scale silently rounds somebody's money — and `reference` is ours while
 * `provider_order_id` is theirs, because a webhook may arrive knowing only one
 * of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onramp_orders', function (Blueprint $table) {
            $table->id();

            // Ours, minted server-side, carried into the provider's own
            // partner-order field. Unique: it is what a webhook is matched on.
            $table->uuid('reference')->unique();
            $table->string('provider', 32);
            $table->string('provider_order_id', 128)->nullable();

            // Optional on purpose. Most of this wallet has no account at all,
            // and requiring one to buy would put a login in front of the one
            // screen whose whole job is a first purchase.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 24)->default('pending');
            $table->string('fiat', 8);
            $table->string('fiat_amount', 32);
            $table->string('crypto_amount', 64)->nullable();
            $table->string('chain', 32);
            $table->string('asset', 24);
            $table->string('address', 128);
            $table->string('method', 24)->default('card');
            $table->string('tx_hash', 128)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_order_id']);
            $table->index(['address', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onramp_orders');
    }
};
