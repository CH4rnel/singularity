<?php

namespace App\Http\Controllers;

use App\Services\Console\ServiceStrips;
use App\Services\Monitoring\ServiceBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Машины": is everything running, and is anyone using it.
 *
 * Behind the same wallet allowlist as the rest of the console, and for the
 * same reason — this page names every internal daemon, every container and
 * every dormant product on the host, which is a map nobody outside needs.
 *
 * It renders whatever the last sweep found and never probes anything itself.
 * A dashboard that runs its own checks on page load is a dashboard that
 * disagrees with the alerts, is slow exactly when the network is, and quietly
 * DDoSes its own services once somebody leaves it open on a second monitor.
 *
 * The table became a grid of tiles: forty-six rows are read line by line,
 * forty-six tiles are read at a glance, and what is broken is lifted out of
 * the grid entirely into its own band at the top.
 */
class ServiceMonitorController extends Controller
{
    public function __construct(
        private ServiceBoard $board,
        private ServiceStrips $strips,
    ) {}

    public function index(): Response
    {
        return Inertia::render('crm/Machines', $this->board->build() + [
            // A day per service, one cell an hour. The last sweep says what
            // is true now; the strip says whether it has been true all night.
            'strips' => $this->strips->all(),
            'settings' => [
                // Printed on the page so the numbers can be read correctly:
                // "checked 4 minutes ago" means something different when the
                // sweep runs every five minutes than when it runs hourly.
                'stale_seconds' => (int) config('monitoring.heartbeat.stale_seconds', 240),
                'retention_days' => (int) config('monitoring.retention.check_days', 30),
                'alerts' => (bool) config('monitoring.alerts.enabled', true),
                'heartbeat_configured' => (string) config('monitoring.heartbeat.token', '') !== '',
            ],
        ]);
    }
}
