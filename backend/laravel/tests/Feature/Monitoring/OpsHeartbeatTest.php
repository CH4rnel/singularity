<?php

use App\Models\ServiceHeartbeat;

/**
 * The host's report, and who is allowed to file one.
 *
 * The token is the whole security model here: this endpoint is reachable from
 * the public internet and what it writes is believed by every host-side check
 * on the board. An open ingest would let anyone declare a dead host healthy,
 * which is strictly worse than having no monitoring — silence is at least
 * honest.
 */
beforeEach(function () {
    config()->set('monitoring.heartbeat.token', 'secret-token');
});

function heartbeat(array $overrides = []): array
{
    return array_merge([
        'host' => 'cyber.main',
        'cpus' => 4,
        'load' => [1.2, 1.1, 1.0],
        'memory' => ['total_mb' => 8000, 'available_mb' => 3000],
        'disk' => ['path' => '/', 'used_percent' => 72, 'free_gb' => 33],
        'containers' => [
            ['name' => 'proxy', 'state' => 'running', 'status' => 'Up 3 days', 'restarts' => 0],
        ],
        'tmux' => ['bot'],
        'processes' => ['lainos' => 0],
        'crons' => ['distribute-tg' => ['log_age_seconds' => 30, 'log_size_mb' => 9]],
    ], $overrides);
}

it('stores a report from a reporter with the right token', function () {
    $this->withHeader('X-Ops-Token', 'secret-token')
        ->postJson('/api/ops/heartbeat', heartbeat())
        ->assertOk()
        ->assertJson(['ok' => true]);

    $record = ServiceHeartbeat::sole();

    expect($record->host)->toBe('cyber.main')
        ->and($record->payload['tmux'])->toBe(['bot'])
        ->and($record->payload['containers'][0]['restarts'])->toBe(0);
});

it('refuses a report with the wrong token', function () {
    $this->withHeader('X-Ops-Token', 'wrong')
        ->postJson('/api/ops/heartbeat', heartbeat())
        ->assertNotFound();

    expect(ServiceHeartbeat::count())->toBe(0);
});

it('refuses every report when no token is configured', function () {
    config()->set('monitoring.heartbeat.token', null);

    // Not a convenience default: an unconfigured deploy must be *closed*, not
    // open, or the first thing anyone learns about this endpoint is from
    // somebody else's traffic.
    $this->withHeader('X-Ops-Token', '')
        ->postJson('/api/ops/heartbeat', heartbeat())
        ->assertNotFound();

    $this->postJson('/api/ops/heartbeat', heartbeat())->assertNotFound();
});

it('keeps one row per host, overwritten', function () {
    $this->withHeader('X-Ops-Token', 'secret-token')
        ->postJson('/api/ops/heartbeat', heartbeat())
        ->assertOk();

    $this->withHeader('X-Ops-Token', 'secret-token')
        ->postJson('/api/ops/heartbeat', heartbeat(['tmux' => ['bot', 'lain']]))
        ->assertOk();

    // A heartbeat is a snapshot of now. Its history is already recorded as the
    // service_checks it produced, and a second copy would be the biggest table
    // in the database within a month.
    expect(ServiceHeartbeat::count())->toBe(1)
        ->and(ServiceHeartbeat::sole()->payload['tmux'])->toBe(['bot', 'lain']);
});

it('rejects a malformed report rather than storing half of it', function () {
    $this->withHeader('X-Ops-Token', 'secret-token')
        ->postJson('/api/ops/heartbeat', heartbeat([
            'containers' => [['state' => 'running']], // no name
        ]))
        ->assertStatus(422);

    expect(ServiceHeartbeat::count())->toBe(0);
});

it('bounds how much one report may carry', function () {
    $this->withHeader('X-Ops-Token', 'secret-token')
        ->postJson('/api/ops/heartbeat', heartbeat([
            'containers' => array_fill(0, 101, [
                'name' => 'x', 'state' => 'running', 'status' => 'Up', 'restarts' => 0,
            ]),
        ]))
        ->assertStatus(422);
});

it('timestamps the report with this server clock', function () {
    $this->travelTo(now()->setTime(12, 0));

    $this->withHeader('X-Ops-Token', 'secret-token')
        ->postJson('/api/ops/heartbeat', heartbeat(['reported_at' => '1999-01-01T00:00:00Z']))
        ->assertOk();

    // A reporter with a wrong clock would otherwise look permanently stale or
    // permanently fresh, and staleness is the entire signal this carries.
    expect(ServiceHeartbeat::sole()->reported_at->format('H:i'))->toBe('12:00');
});
