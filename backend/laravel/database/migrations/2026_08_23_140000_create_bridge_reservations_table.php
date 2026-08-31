<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bridge's promise ledger.
 *
 * A live balance read is a fact about the past the moment it is returned: two
 * browsers can read the same 1.0 SOL, both send 0.6, and only one of them can
 * be paid. This table is what makes the second one impossible — capacity is
 * claimed here, under a lock, BEFORE the user is asked to sign anything, and a
 * claim keeps counting against the balance until the payout has actually left.
 *
 * A row's whole life:
 *   pending_source — capacity is held, the user has not signed yet. Expires on
 *                    its own; an abandoned tab gives the capacity back.
 *   committed      — the source transfer exists. This is now an obligation of
 *                    the bridge and NOTHING releases it: not an expiry, not a
 *                    failed payout, not a restart. Only settlement does.
 *   settled        — the payout was broadcast. The balance has really moved,
 *                    so the live read now reflects it and double-counting it
 *                    here would understate capacity forever.
 *   released       — expired without a source transfer, or the deposit was
 *                    never verified. Capacity returns to the pool.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_reservations', function (Blueprint $table) {
            $table->id();
            // Unpredictable handle: the browser gets this and nothing else, so
            // a reservation cannot be guessed and spent by somebody else.
            $table->string('reference', 64)->unique();
            // Destination chain + payout asset. Corridors sharing one balance
            // share one pool, one lock and one running total.
            $table->string('pool')->index();
            $table->string('direction');
            $table->string('token');
            // Exact net payout in the DESTINATION entry's decimals, as an
            // integer string. Never a float, never the source side's scale.
            $table->string('net_raw', 78);
            $table->unsignedTinyInteger('decimals');
            $table->string('amount', 78);
            $table->string('sender_address')->nullable();
            $table->string('recipient_address');
            $table->foreignId('bridge_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending_source');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            // The capacity query: outstanding claims on one pool.
            $table->index(['pool', 'status']);
            // One reservation per request — the uniqueness that makes commit
            // a once-only operation even under two concurrent submits.
            $table->unique('bridge_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_reservations');
    }
};
