<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time links that tie a Telegram account to a wallet address.
 *
 * The wallet proves the address with a signature and gets a `start=link_…`
 * deep link; the bot consumes the token when that Telegram account opens it
 * and writes `tg_wallets`. Shared with `services/telegram-bot`, which creates
 * the same table too, because either side may start first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tg_wallet_links')) {
            Schema::create('tg_wallet_links', function (Blueprint $table) {
                $table->string('token')->primary();
                $table->string('address');
                $table->string('expires_at');
                $table->string('used_at')->nullable();
                $table->string('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_wallet_links');
    }
};
