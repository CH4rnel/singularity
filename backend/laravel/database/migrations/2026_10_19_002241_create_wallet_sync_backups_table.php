<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One encrypted copy of a person's wallet, kept with their profile so the same
 * phrase opens on the site, in Telegram and in the apps.
 *
 * The browser seals it under a sync password before it is sent; this table
 * holds ciphertext, the KDF parameters needed to open it, and the address it
 * derives (to say which wallet it is without opening it). Never the phrase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_sync_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('address', 42);
            $table->text('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_sync_backups');
    }
};
