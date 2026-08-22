<?php

namespace App\Http\Controllers;

use App\Services\Monitoring\ServiceBoard;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator's board: is everything running, and is anyone using it.
 *
 * Behind the same wallet allowlist as the rest of the CRM, and for the same
 * reason — this page names every internal daemon, every container and every
 * dormant product on the host, which is a map nobody outside needs.
 *
 * It renders whatever the last sweep found and never probes anything itself.
 * A dashboard that runs its own checks on page load is a dashboard that
 * disagrees with the alerts, is slow exactly when the network is, and quietly
 * DDoSes its own services once somebody leaves it open on a second monitor.
 */
class ServiceMonitorController extends Controller
{
    public function __construct(private ServiceBoard $board) {}

    public function index(): Response
    {
        return Inertia::render('crm/Services', $this->board->build() + [
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
