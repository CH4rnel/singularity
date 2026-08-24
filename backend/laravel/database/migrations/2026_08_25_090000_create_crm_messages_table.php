<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was actually said to this person, and what they said back.
 *
 * The dossier could already hold what an operator *thought* (a note) and what
 * the chain *did* (a transfer), but not the correspondence itself — so the
 * one thing every conversation with a whale turns on ("when did we last write
 * to them, and did they answer") lived in somebody's Telegram and nowhere on
 * the record.
 *
 * Two columns carry the whole design. `direction` says who spoke, because a
 * log where our message and their reply look alike answers neither question;
 * `sent_at` is when it was said rather than when it was typed in, because
 * these lines are entered after the fact and later imported from Telegram and
 * Discord, where the timestamp is the import's whole point.
 *
 * `external_id` is that import's guard: unique per channel, so replaying an
 * export writes each line once. Null for anything typed by hand — every
 * engine here allows repeated nulls in a unique index, which is exactly the
 * behaviour wanted: a hand-written line has no external identity to collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_contact_id')->constrained()->cascadeOnDelete();
            // Which operator wrote this line down; null once that user is
            // deleted, or for a line an importer created with nobody behind it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // out = we wrote to them, in = they wrote to us.
            $table->string('direction')->default('out');
            // telegram | discord | x | email | call | other
            $table->string('channel')->default('telegram');
            $table->text('body');
            // The name the other side went by in the source, kept only when an
            // import knows it — a Discord display name is not our contact's name.
            $table->string('author_name')->nullable();
            $table->timestamp('sent_at')->index();
            $table->string('external_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['crm_contact_id', 'sent_at']);
            $table->unique(['channel', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_messages');
    }
};
