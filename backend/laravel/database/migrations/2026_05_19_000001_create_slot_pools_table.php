<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('paused'); // active | paused
            $table->string('hot_wallet_address')->nullable();
            $table->unsignedInteger('burn_bps')->default(200);
            $table->unsignedInteger('house_edge_bps')->default(400);
            $table->unsignedInteger('jackpot_threshold_bps')->default(10);
            $table->unsignedInteger('max_single_win_bps')->default(2000);
            $table->unsignedInteger('jackpot_basket_bps')->default(2500);
            $table->unsignedInteger('jackpot_basket_size')->default(5);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_pools');
    }
};
