<?php

namespace App\Console\Commands;

use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Keep the monitor from becoming the biggest table in the database.
 *
 * Thirty-odd services swept every five minutes is roughly ten thousand rows a
 * day — fine as a rolling uptime window, absurd as an archive. Incidents are
 * the history worth keeping and are perhaps a handful a month, so they live a
 * year and are pruned separately.
 */
#[Signature('services:prune')]
#[Description('Drop service checks and incidents past their retention window')]
class ServicesPruneCommand extends Command
{
    public function handle(): int
    {
        $checkDays = (int) config('monitoring.retention.check_days', 30);
        $incidentDays = (int) config('monitoring.retention.incident_days', 365);

        $checks = ServiceCheck::query()
            ->where('checked_at', '<', now()->subDays($checkDays))
            ->delete();

        // Resolved only: an incident that is still open is still current, no
        // matter when it started, and dropping it would reopen it on the next
        // sweep and alert about a year-old outage as if it were new.
        $incidents = ServiceIncident::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays($incidentDays))
            ->delete();

        $this->info("Pruned {$checks} checks (>{$checkDays}d) and {$incidents} incidents (>{$incidentDays}d).");

        return self::SUCCESS;
    }
}
