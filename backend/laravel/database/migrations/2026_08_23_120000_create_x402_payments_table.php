<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts for what x402 collected.
 *
 * Money is its own subject, so it gets its own table rather than columns bolted
 * onto the metering log: a payment is settled once, by an address, on a chain,
 * for a resource — facts that outlive whichever request they bought and that
 * an operator reconciles against a block explorer, not against token counts.
 *
 * `amount` is a string because it is atomic units of an ERC-20, and uint256
 * does not fit in a database integer. A row is written when an authorization
 * verifies and stamped when the chain takes it, so an unsettled row is the
 * record of money that was promised and never collected.
 *
 * There is no column here a prompt could go in, exactly as in ai_api_requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x402_payments', function (Blueprint $table) {
            $table->id();
            // The resource that was bought, as its route path — the URL would
            // carry query strings, and this is for grouping, not for replay.
            $table->string('resource', 191)->index();
            // EVM addresses are 42 characters, Solana's base58 up to 44.
            $table->string('payer', 64)->index();
            $table->string('network', 40);
            $table->string('scheme', 24)->default('exact');
            $table->string('asset', 64);
            $table->string('amount', 78);
            $table->string('transaction', 100)->nullable();
            // Stamped when the facilitator confirmed the chain took it. A row
            // without it is an authorization that was verified and then not
            // collected — the upstream failed, or settlement did — and that is
            // a fact worth keeping rather than a row worth deleting.
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payer', 'created_at']);
            $table->index('created_at');
        });

        Schema::table('ai_api_requests', function (Blueprint $table) {
            // A paid call has no key: x402's whole claim is that a caller needs
            // no account, so the metering row must be able to name a payment
            // instead of a credential.
            $table->foreignId('x402_payment_id')->nullable()->after('ai_api_key_id')
                ->constrained('x402_payments')->nullOnDelete();
        });

        Schema::table('ai_api_requests', function (Blueprint $table) {
            $table->foreignId('ai_api_key_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_api_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('x402_payment_id');
        });

        Schema::dropIfExists('x402_payments');
    }
};
