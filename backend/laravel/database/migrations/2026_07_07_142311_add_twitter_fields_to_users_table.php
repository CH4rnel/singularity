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
        Schema::table('users', function (Blueprint $table) {
            // X (Twitter) OAuth identity: the immutable account id is the
            // login key; the handle is display-only and may change.
            $table->string('twitter_id')->nullable()->unique()->after('solana_wallet_address');
            $table->string('twitter_username')->nullable()->after('twitter_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['twitter_id', 'twitter_username']);
        });
    }
};
