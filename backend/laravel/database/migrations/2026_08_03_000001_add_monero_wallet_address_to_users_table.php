<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The user's own Monero wallet: a native address (standard, integrated or
     * subaddress, up to 106 characters) they get paid out to. Unlike the EVM
     * and Solana columns this is not an identity — Monero cannot sign a
     * message in a browser, so ownership is unproven and the address is
     * deliberately NOT unique and never used to look an account up.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('monero_wallet_address', 106)->nullable()->after('solana_wallet_address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('monero_wallet_address');
        });
    }
};
