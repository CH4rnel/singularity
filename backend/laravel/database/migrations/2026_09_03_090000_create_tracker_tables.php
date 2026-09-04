<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tracker: an index of releases, and the swarms reporting to it.
 *
 * Two tables with opposite lifetimes, which is why they are two tables. A
 * release is permanent — it is a token, and the token does not stop existing
 * — while a peer is a row that is true for the next fifteen minutes and is
 * deleted rather than updated when it stops being true.
 *
 * The identity of a release is its info hash and nothing else: the same forty
 * hex characters every client in the world already agrees on. The token id is
 * a unique column beside it, so one token can name one release and a second
 * mint of the same content is refused instead of splitting the swarm's
 * bookkeeping in two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_releases', function (Blueprint $table) {
            $table->id();

            // Lowercase hex, always. The wire format is twenty raw bytes and
            // the database format is their hex — one place to convert, and
            // every index, log line and URL stays copy-pasteable.
            $table->char('info_hash', 40)->unique();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 20)->default('other');

            // What the torrent's own metadata declares, not what a client
            // reports: total bytes and how many files they are spread over.
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('file_count')->default(0);

            // The file list, as `[{path, length}]`. A release is read whole or
            // not at all — nothing queries inside it — and a table would cost
            // a join per row on a page that shows twenty of them.
            $table->json('files')->nullable();

            $table->text('magnet');

            // The mint. This is the release's credential: it was read off the
            // chain by this server, never accepted from the submitter.
            $table->unsignedBigInteger('chain_id');
            $table->char('contract', 42);
            $table->string('token_id', 78);
            $table->char('owner', 42);
            $table->text('token_uri');

            // Something playable without joining the swarm, when the minter
            // pinned one: a preview, a cover, a whole track for a small
            // release. `ipfs://` or https, whatever the metadata carried.
            $table->text('preview_url')->nullable();
            $table->text('cover_url')->nullable();
            $table->string('media_kind', 10)->default('other');

            // Swarm counters, recomputed on announce rather than read live:
            // the index shows twenty rows and must not run twenty aggregates.
            $table->unsignedInteger('seeders')->default(0);
            $table->unsignedInteger('leechers')->default(0);
            $table->unsignedInteger('completed')->default(0);
            $table->timestamp('last_announce_at')->nullable();

            // An operator's only lever, and it hides rather than deletes: the
            // token stays minted whatever this column says, and a row that was
            // deleted could be re-registered by anyone the same minute.
            $table->timestamp('hidden_at')->nullable();

            $table->timestamps();

            $table->unique(['chain_id', 'contract', 'token_id']);
            $table->index(['hidden_at', 'created_at']);
            $table->index(['category', 'hidden_at']);
            $table->index('owner');
        });

        Schema::create('tracker_peers', function (Blueprint $table) {
            $table->id();
            $table->char('info_hash', 40);

            // The client's own 20 bytes, hex-encoded for the same reason as
            // the info hash. Two clients on one address are two peers, so the
            // peer id is half of the identity and the address is not part of
            // it at all — a peer that changes address is the same peer.
            $table->char('peer_id', 40);

            $table->string('ip', 45);
            $table->unsignedInteger('port');

            // Zero left means a seeder. It is stored rather than derived
            // because "seeders" and "leechers" are counted from it on every
            // announce and the client is the only thing that knows.
            $table->unsignedBigInteger('left_bytes')->default(0);
            $table->unsignedBigInteger('uploaded')->default(0);
            $table->unsignedBigInteger('downloaded')->default(0);
            $table->boolean('seeder')->default(false);

            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['info_hash', 'peer_id']);
            $table->index(['info_hash', 'seeder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_peers');
        Schema::dropIfExists('tracker_releases');
    }
};
