<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the wallet's end-to-end encrypted chat.
 *
 * Two tables and nothing else, because the server's whole job here is to be a
 * queue it cannot read: a directory of public keys, and envelopes waiting for
 * the address they are addressed to.
 *
 * `sent_at` and `issued_at` are strings, deliberately. Both are covered by a
 * signature or an AEAD tag that was computed over the exact characters the
 * browser wrote, so a timestamp column — which would normalise the format on
 * the way back out — would break every verification it touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_chat_keys', function (Blueprint $table) {
            $table->id();
            // One key per address, replaced when a newer one is signed.
            $table->string('address', 42)->unique();
            // 33-byte compressed secp256k1 point, 0x-hex.
            $table->string('public_key', 68);
            $table->string('issued_at', 40);
            $table->string('signature', 132);
            $table->timestamps();
        });

        Schema::create('wallet_chat_messages', function (Blueprint $table) {
            $table->id();
            // The sender's own id for this message. Part of what the tag
            // covers, and unique so a retried send cannot become two messages.
            $table->string('message_id', 64)->unique();
            $table->string('from_address', 42);
            $table->string('to_address', 42);
            $table->string('sent_at', 40);
            // AES-GCM nonce and ciphertext, both base64. Opaque here.
            $table->string('iv', 32);
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            // Both directions of one mailbox: what is addressed to an account,
            // and what it sent — a second device recovers its own half too.
            $table->index(['to_address', 'id']);
            $table->index(['from_address', 'id']);
            // Retention sweeps by age.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_chat_messages');
        Schema::dropIfExists('wallet_chat_keys');
    }
};
