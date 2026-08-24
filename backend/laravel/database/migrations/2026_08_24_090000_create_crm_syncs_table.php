<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per import run: when the base was last actually refreshed.
     *
     * The console has to answer "how old is what I am looking at", and until
     * now nothing recorded it: `crm_contacts.last_synced_at` is per contact
     * and is stamped by the half-hourly balance refresh, so the newest one
     * says a balance was read, not that the base was rebuilt.
     *
     * The row also carries whether the run was **complete**. The holder scan
     * is one `getProgramAccounts` call against a public RPC, and a rate-limit
     * is answered with an empty result rather than an error — a date that
     * says "updated a minute ago" over a run that read nothing is the exact
     * lie this table exists to prevent.
     */
    public function up(): void
    {
        Schema::create('crm_syncs', function (Blueprint $table) {
            $table->id();

            // Who asked: the scheduler, or an operator pressing the button.
            $table->string('trigger')->default('schedule');

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // Per-importer counts, as the service returns them.
            $table->json('counts')->nullable();

            // What the run changed about people, which is what an operator
            // reads: how many are new, and how many stopped holding.
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('sold')->default(0);

            // Set when a source could not be read, so the date can say that
            // the base is only partly refreshed instead of pretending.
            $table->string('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_syncs');
    }
};
