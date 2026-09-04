<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a task came from, when it did not come from a person.
 *
 * LainOS works while nobody is watching — it forges wishes, takes profit,
 * fires watches and brings back digests — and none of that reached the board
 * that is supposed to say what this project is doing. It does now, over a
 * token-gated ingest, and a daemon that retries a request it never saw the
 * answer to must not be able to write the same line twice.
 *
 * So the id is the sender's, not ours: `lainos:trade:0x…`, `lainos:wish:12`.
 * Nullable, because every task an operator types has no such id and never
 * will; unique, because that is the whole point of storing it. The namespace
 * in front is deliberate — it is what makes "everything the daemon wrote"
 * a query rather than a guess, without a second column nothing reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->string('external_id', 120)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_tasks', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropColumn('external_id');
        });
    }
};
