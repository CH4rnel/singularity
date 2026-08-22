<?php

use App\Models\ServiceCheck;
use App\Models\ServiceHeartbeat;
use App\Models\ServiceIncident;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * /crm/services — the operator's board.
 *
 * Behind the same wallet allowlist as the rest of the CRM, and for a sharper
 * reason than the contact list: this page names every internal daemon, every
 * container and every dormant product on the host, which is a map of where to
 * push if you wanted to break something.
 */
beforeEach(function () {
    $this->withoutVite();

    config()->set('crm.admin_wallets', ['0x00000000000000000000000000000000000000aa']);
    config()->set('monitoring.services', [
        'demo' => [
            'group' => 'web',
            'label' => 'Demo',
            'critical' => true,
            'check' => ['type' => 'http', 'url' => 'https://demo.test'],
            'usage' => null,
        ],
    ]);
});

function crmAdmin(): User
{
    return User::factory()->create([
        'wallet_address' => '0x00000000000000000000000000000000000000aa',
    ]);
}

it('is a 404 for everyone who is not an operator', function () {
    $this->get('/crm/services')->assertRedirect();

    $this->actingAs(User::factory()->create())
        ->get('/crm/services')
        ->assertNotFound();
});

it('renders the last sweep without probing anything', function () {
    ServiceCheck::create([
        'service' => 'demo',
        'status' => 'down',
        'latency_ms' => 900,
        'detail' => ['reason' => 'bad-status', 'status' => 500],
        'checked_at' => now()->subMinutes(2),
    ]);

    ServiceIncident::create([
        'service' => 'demo',
        'status' => 'down',
        'reason' => 'bad-status',
        'started_at' => now()->subHour(),
    ]);

    // No Http::fake and no preventStrayRequests escape hatch: if this page
    // reached the network at all, the test suite would have to fake the
    // internet to render a dashboard.
    $this->actingAs(crmAdmin())
        ->get('/crm/services')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Services')
            ->where('services.0.key', 'demo')
            ->where('services.0.status', 'down')
            ->where('services.0.reason', 'bad-status')
            ->where('services.0.incident.status', 'down')
            ->where('summary.counts.down', 1)
            ->where('summary.critical_down', 1)
            ->where('incidents.0.label', 'Demo')
        );
});

it('says nothing about hosts when no heartbeat has arrived', function () {
    $this->actingAs(crmAdmin())
        ->get('/crm/services')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hosts', []));
});

it('reports each machine that starts talking', function () {
    ServiceHeartbeat::create([
        'host' => 'cyber.main',
        'payload' => [
            'host' => 'cyber.main',
            'cpus' => 4,
            'load' => [15.4, 15.1, 15.2],
            'disk' => ['path' => '/', 'used_percent' => 72, 'free_gb' => 33],
        ],
        'reported_at' => now(),
    ]);

    $this->actingAs(crmAdmin())
        ->get('/crm/services')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hosts.0.host', 'cyber.main')
            ->where('hosts.0.stale', false)
            ->where('hosts.0.metrics.cpus', 4)
        );
});

it('tells the operator when the ingest token is unset', function () {
    config()->set('monitoring.heartbeat.token', null);

    // Without it two thirds of the board reads `unknown`, so the page says so
    // once at the top instead of leaving it to be inferred from grey rows.
    $this->actingAs(crmAdmin())
        ->get('/crm/services')
        ->assertInertia(fn (Assert $page) => $page->where('settings.heartbeat_configured', false));
});
