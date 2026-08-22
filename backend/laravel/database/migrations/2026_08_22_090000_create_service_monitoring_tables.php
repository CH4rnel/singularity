<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One row per service per sweep. This is the uptime window and nothing
         * more: it is pruned on a rolling schedule, and the history worth
         * keeping lives in service_incidents next door.
         *
         * The service key is a plain string rather than a foreign key on
         * purpose — the registry is config/monitoring.php, so adding or
         * renaming a service is an edit to one file and never a migration,
         * and rows for a service that no longer exists age out on their own.
         */
        Schema::create('service_checks', function (Blueprint $table) {
            $table->id();
            $table->string('service', 64);
            $table->string('status', 16);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('checked_at');

            $table->index(['service', 'checked_at']);
            $table->index('checked_at');
        });

        /*
         * A period during which a service was not `up`. Opened when a service
         * has failed enough consecutive checks to be believed, closed when it
         * answers again — so "how long was it down" is a stored fact rather
         * than something reconstructed from a scatter of check rows.
         *
         * `notified_at` and `reminded_at` are what keep the alert channel
         * readable: an incident is announced once, reminded about at most
         * once, and announced again only when it resolves.
         */
        Schema::create('service_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('service', 64);
            $table->string('status', 16);
            $table->string('reason', 255)->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['service', 'started_at']);
            // The open-incident lookup every sweep performs.
            $table->index(['service', 'resolved_at']);
        });

        /*
         * What the host last said about itself. One row per host, overwritten
         * in place: a heartbeat is a snapshot of *now*, and its history is
         * already recorded as the service_checks it produced.
         */
        Schema::create('service_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('host', 128)->unique();
            $table->json('payload');
            $table->timestamp('reported_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_heartbeats');
        Schema::dropIfExists('service_incidents');
        Schema::dropIfExists('service_checks');
    }
};
