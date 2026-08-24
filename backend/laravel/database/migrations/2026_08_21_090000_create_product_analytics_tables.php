<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product and marketing analytics for the wallet.
 *
 * `site_events` already answers "did this browser visit and convert on the
 * site". This answers a different question, about a different product: of the
 * people who installed the wallet, how many funded it, how many did something
 * with it, and which of them came back. The two are kept apart rather than
 * merged because their subject differs — a site session is a browser reading
 * pages, an analytics user is an installation of a non-custodial wallet.
 *
 * The identity here is an anonymous UUID the client generates on first run and
 * keeps locally. It is deliberately *not* a blockchain address: one person owns
 * several addresses across several networks, and counting addresses would
 * inflate every number on the dashboard. Addresses live in their own table and
 * only for the two things they actually buy — verifying that a wallet was
 * funded, and attributing what a sponsored drip cost — and only on the chains
 * this server can read without a key.
 *
 * Nothing in these tables can hold a secret. Seed phrases, private keys,
 * passwords, vault material and signed payloads are rejected at ingest by the
 * property allowlist in EventTaxonomy, not merely "not sent".
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One installation of the wallet, plus the milestones that define the
         * funnel. The milestones are columns rather than derived from the event
         * stream every time a dashboard loads: they are stamped once, they are
         * the thing every cohort query joins on, and a first_transaction that
         * moved because a client resent an old event would be a silent lie.
         */
        Schema::create('analytics_users', function (Blueprint $table) {
            // The anonymous_user_id itself. A client-generated UUID, which is
            // why every ingest validates the shape before it can create a row.
            $table->uuid('id')->primary();

            $table->timestamp('created_at')->index();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();

            // Where this installation runs *now*: a person upgrades, and a
            // filter for "who is on the new build" wants the current answer.
            $table->string('platform', 24)->nullable()->index();
            $table->string('app_version', 32)->nullable()->index();
            $table->string('language', 12)->nullable();

            /*
             * First-touch attribution, written once and never overwritten.
             * A campaign that gets credit for a user it merely re-touched is
             * the standard way an acquisition report lies about itself.
             */
            $table->string('source', 100)->nullable()->index();
            $table->string('medium', 100)->nullable();
            $table->string('campaign', 100)->nullable()->index();
            $table->string('content', 100)->nullable();
            // Origin only. A full referring URL is a browsing history.
            $table->string('referrer', 255)->nullable();
            $table->string('landing_path', 255)->nullable();

            // Onboarding: when a vault first existed here, and how it got here.
            $table->timestamp('wallet_created_at')->nullable();
            $table->string('wallet_origin', 16)->nullable();

            /*
             * Funding, with its provenance. 'onchain' means this server read a
             * positive balance itself; 'client' means the browser said so and
             * the chain could not be checked from here. They are never summed
             * without saying which is which.
             */
            $table->timestamp('funded_at')->nullable()->index();
            $table->string('funded_chain', 32)->nullable();
            $table->string('funded_source', 16)->nullable();

            // Activation: the first meaningful action, and which one it was.
            $table->timestamp('activated_at')->nullable()->index();
            $table->string('activation_event', 48)->nullable();

            // The first transaction of any kind, confirmed. Time-to-first-
            // transaction is measured from first_seen_at to this.
            $table->timestamp('first_transaction_at')->nullable();
        });

        /*
         * A visit. New after 30 minutes of inactivity, decided on the client
         * (which is the only side that knows about inactivity) and recorded
         * here so session counts and "sessions before activation" are askable.
         */
        Schema::create('analytics_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestamp('started_at')->index();
            $table->timestamp('last_activity_at');
            // Set by the client when it opens a new session, so an abandoned
            // session is closed retroactively rather than left open forever.
            $table->timestamp('ended_at')->nullable();
            $table->string('platform', 24)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->index(['user_id', 'started_at']);
        });

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();

            /*
             * The idempotency key, generated by the client per event and never
             * reused across retries. A wallet that lost the network mid-flush,
             * a reloaded page replaying its outbox and a duplicated beacon all
             * arrive with an id that is already here, and are dropped.
             */
            $table->uuid('event_id')->unique();

            $table->uuid('user_id');
            $table->uuid('session_id')->nullable();
            $table->string('event', 48);

            /*
             * Promoted out of `properties` because it is the one dimension
             * asked of nearly every product question ("swaps on Cyberia",
             * "failures on Base"), and SQLite cannot use an index for a
             * json_extract filter without an expression index per query.
             */
            $table->string('chain', 32)->nullable();

            $table->json('properties')->nullable();

            // Server time is the source of truth everywhere in this schema.
            $table->timestamp('created_at');
            /*
             * What the client claimed the time was. Kept only so clock skew is
             * measurable — no metric reads it, because a device with a wrong
             * clock would otherwise move a retention cohort.
             */
            $table->timestamp('client_time')->nullable();

            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
            /*
             * The time-series chart is the one dashboard query with no event
             * and no user in its filter, so none of the composite indexes can
             * seek for it — without this it scans the whole table (as a
             * covering scan, but a whole one) on every load.
             */
            $table->index('created_at');
            // Retention and activation both ask "did this user do one of these
            // events in this window", which is exactly this index.
            $table->index(['user_id', 'event', 'created_at']);
            $table->index(['session_id', 'created_at']);
        });

        /*
         * The link between an anonymous installation and an on-chain address.
         *
         * Written only for chains this server can read without an API key,
         * because the only reasons to hold an address at all are reading a
         * balance to confirm funding and joining `gas_sponsorships` to find
         * what a drip cost. A chain that buys neither is not stored.
         */
        Schema::create('analytics_addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->string('chain', 32);
            $table->string('address', 128);
            $table->timestamp('created_at');

            $table->unique(['user_id', 'chain', 'address']);
            // Sponsored gas arrives keyed by address; this is how it finds the
            // user it belongs to.
            $table->index('address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_addresses');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('analytics_sessions');
        Schema::dropIfExists('analytics_users');
    }
};
