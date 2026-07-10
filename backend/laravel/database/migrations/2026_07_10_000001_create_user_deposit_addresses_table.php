<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Per-user CEX-style deposit addresses (BTC/LTC/YTN P2PKH derived from
        // an HD seed at index = user id; XMR integrated address). Derivation is
        // deterministic, but an address shown to a user is honored forever —
        // persisting pins it across any future seed rotation and lets ops
        // attribute an incoming deposit to its owner with a single lookup.
        Schema::create('user_deposit_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chain');
            $table->string('address')->unique();
            $table->timestamps();

            $table->unique(['user_id', 'chain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_deposit_addresses');
    }
};
