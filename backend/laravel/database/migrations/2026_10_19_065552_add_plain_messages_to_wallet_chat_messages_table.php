<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages that are not encrypted, in the same queue as the ones that are.
 *
 * An encrypted message needs a key on both ends, and an address has one only
 * after its owner opened the wallet's chat — so most of the site's people
 * could not be written to at all. A plain message needs nothing but the
 * address: the relay holds its text, the wallet says so on every one of them,
 * and the recipient can be told it arrived.
 *
 * One table rather than two, because a conversation is one thread whichever
 * way each message in it travelled, and the relay's own id is what orders it.
 * `sealed` says which kind a row is; a plain row has no nonce, so `iv` becomes
 * nullable, and its `body` is the text rather than ciphertext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_chat_messages', function (Blueprint $table) {
            $table->boolean('sealed')->default(true)->after('to_address');
            $table->string('iv', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_chat_messages', function (Blueprint $table) {
            $table->dropColumn('sealed');
        });
    }
};
