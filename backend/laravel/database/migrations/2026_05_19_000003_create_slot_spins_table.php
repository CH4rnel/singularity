<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_spins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('slot_pool_id')->constrained()->cascadeOnDelete();
            $table->string('wallet_address');
            $table->string('bet_mint');
            $table->string('bet_amount'); // raw
            $table->string('deposit_address'); // hot wallet
            $table->string('deposit_tx_hash')->nullable();
            $table->string('server_seed')->nullable(); // revealed on settle
            $table->string('server_seed_hash');
            $table->string('client_seed');
            $table->unsignedBigInteger('nonce');
            $table->json('reels')->nullable(); // 3x3 mints
            $table->string('outcome_type')->default('pending'); // pending | loss | win | jackpot
            $table->json('prize_payload')->nullable();
            $table->string('payout_tx_hash')->nullable();
            $table->string('burn_amount')->nullable();
            $table->string('status')->default('prepared'); // prepared | deposit_seen | settled | failed | expired
            $table->text('error_message')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique('deposit_tx_hash');
            $table->index(['wallet_address', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_spins');
    }
};
