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
        Schema::create('solana_staking_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('solana_address')->unique();
            $table->string('principal_raw')->default('0');
            $table->string('accrued_ash_raw')->default('0');
            $table->string('reward_remainder')->default('0');
            $table->string('total_deposited_raw')->default('0');
            $table->string('total_withdrawn_raw')->default('0');
            $table->string('total_claimed_ash_raw')->default('0');
            $table->timestamp('accrued_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('solana_staking_positions');
    }
};
