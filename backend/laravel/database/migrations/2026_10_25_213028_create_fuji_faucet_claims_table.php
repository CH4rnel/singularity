<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every drip the Cyberia Fuji faucet has sent.
 *
 * A row is written as soon as the transaction has a hash, before its receipt,
 * so a slow chain cannot turn one request into two payments. Like
 * gas_sponsorships, only a hash of the caller's IP is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuji_faucet_claims', function (Blueprint $table) {
            $table->id();
            $table->string('address', 42)->index();
            $table->string('amount_wei', 78)->default('0');
            $table->string('tx_hash', 66)->nullable()->unique();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['ip_hash', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuji_faucet_claims');
    }
};
