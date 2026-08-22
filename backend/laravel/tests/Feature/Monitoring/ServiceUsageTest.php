<?php

use App\Models\ServiceCheck;
use App\Models\ServiceHeartbeat;
use App\Services\Monitoring\ServiceBoard;
use App\Services\Monitoring\ServiceRegistry;
use App\Services\Monitoring\ServiceUsageService;
use Illuminate\Support\Facades\DB;

/**
 * "Is anyone using this."
 *
 * The distinction under test is the one the whole feature turns on: `unused`
 * is a claim and `unmeasured` is an admission, and collapsing them would put
 * the RPC, the explorer and the DEX on a list recommending their deletion
 * purely because their traffic is recorded in somebody else's access log.
 */
beforeEach(function () {
    config()->set('monitoring.services', [
        'counted' => [
            'group' => 'product',
            'label' => 'Counted',
            'check' => ['type' => 'none'],
            'usage' => ['table' => 'site_events', 'column' => 'created_at', 'distinct' => 'session_id'],
        ],
        'elsewhere' => [
            'group' => 'chain',
            'label' => 'Elsewhere',
            'check' => ['type' => 'none'],
            'usage' => null,
        ],
        'renamed' => [
            'group' => 'product',
            'label' => 'Renamed',
            'check' => ['type' => 'none'],
            'usage' => ['table' => 'a_table_that_was_dropped', 'column' => 'created_at'],
        ],
    ]);
});

function siteEvent(string $session, string $when): void
{
    DB::table('site_events')->insert([
        'session_id' => $session,
        'event' => 'page_view',
        'created_at' => $when,
    ]);
}

it('counts recent use and the people behind it', function () {
    siteEvent('11111111-1111-1111-1111-111111111111', now()->subDay()->toDateTimeString());
    siteEvent('11111111-1111-1111-1111-111111111111', now()->subDays(2)->toDateTimeString());
    siteEvent('22222222-2222-2222-2222-222222222222', now()->subDays(20)->toDateTimeString());
    siteEvent('33333333-3333-3333-3333-333333333333', now()->subDays(90)->toDateTimeString());

    $usage = app(ServiceUsageService::class)->all()['counted'];

    expect($usage['measured'])->toBeTrue()
        ->and($usage['count_7d'])->toBe(2)
        ->and($usage['count_30d'])->toBe(3)
        ->and($usage['actors_30d'])->toBe(2)
        ->and($usage['idle_days'])->toBe(1);
});

it('says it cannot tell rather than saying nobody used it', function () {
    $usage = app(ServiceUsageService::class)->all();

    expect($usage['elsewhere']['measured'])->toBeFalse()
        ->and($usage['elsewhere']['count_30d'])->toBeNull()
        // A registry entry pointing at a table that was renamed must read the
        // same way: unreadable, never "unused".
        ->and($usage['renamed']['measured'])->toBeFalse();
});

it('puts a measurable service with no use on the idle list, and nothing else', function () {
    siteEvent('11111111-1111-1111-1111-111111111111', now()->subDays(60)->toDateTimeString());

    $idle = app(ServiceBoard::class)->build()['idle'];

    expect(array_column($idle, 'key'))->toBe(['counted']);
});

it('leaves the idle list empty when the measurable services are in use', function () {
    siteEvent('11111111-1111-1111-1111-111111111111', now()->subDay()->toDateTimeString());

    expect(app(ServiceBoard::class)->build()['idle'])->toBe([]);
});

it('builds the board out of the last check per service', function () {
    ServiceCheck::create([
        'service' => 'counted',
        'status' => 'down',
        'detail' => ['reason' => 'bad-status', 'status' => 500],
        'checked_at' => now()->subMinutes(10),
    ]);

    ServiceCheck::create([
        'service' => 'counted',
        'status' => 'up',
        'latency_ms' => 42,
        'checked_at' => now(),
    ]);

    $board = app(ServiceBoard::class)->build();
    $service = collect($board['services'])->firstWhere('key', 'counted');

    expect($service['status'])->toBe('up')
        ->and($service['latency_ms'])->toBe(42)
        // Half the conclusive checks were healthy, and the uptime figure says
        // so rather than reporting only the current state.
        ->and($service['uptime_24h'])->toBe(50.0);
});

it('keeps unknown out of the uptime fraction entirely', function () {
    foreach (['up', 'unknown', 'unknown', 'off'] as $status) {
        ServiceCheck::create([
            'service' => 'counted',
            'status' => $status,
            'checked_at' => now(),
        ]);
    }

    $service = collect(app(ServiceBoard::class)->build()['services'])
        ->firstWhere('key', 'counted');

    // One conclusive check, and it was healthy. Counting the blind sweeps as
    // failures would invent downtime for every service at once whenever the
    // heartbeat lapsed.
    expect($service['uptime_24h'])->toBe(100.0);
});

it('names running containers that nobody put on the board', function () {
    ServiceHeartbeat::create([
        'host' => 'test',
        'payload' => ['host' => 'test', 'containers' => [
            ['name' => 'proxy', 'state' => 'running', 'status' => 'Up', 'restarts' => 0],
            ['name' => 'something-new', 'state' => 'running', 'status' => 'Up', 'restarts' => 0],
        ]],
        'reported_at' => now(),
    ]);

    // Nothing in this registry watches a container, so both are unregistered —
    // which is the point: an unwatched container is how a service gets
    // forgotten.
    expect(app(ServiceBoard::class)->build()['hosts'][0]['unregistered'])
        ->toBe(['proxy', 'something-new']);
});

it('lists every registered service, including the ones nothing probes', function () {
    $keys = array_column(app(ServiceBoard::class)->build()['services'], 'key');

    expect($keys)->toBe(app(ServiceRegistry::class)->keys());
});
