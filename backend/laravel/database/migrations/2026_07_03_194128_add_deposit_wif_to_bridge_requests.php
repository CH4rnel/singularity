<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypted spending key (WIF) of a one-time Yenten deposit address, stored so
 * the operator can move the deposited coins to another wallet. Encrypted at the
 * model layer (Laravel `encrypted` cast) — never at rest in plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->text('deposit_wif')->nullable()->after('deposit_address');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_requests', function (Blueprint $table) {
            $table->dropColumn('deposit_wif');
        });
    }
};
