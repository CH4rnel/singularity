<?php

use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Services\Console\ConsoleBriefing;
use App\Services\Console\ConsoleFeed;
use App\Services\GasSponsorService;
use Illuminate\Support\Facades\Cache;

/**
 * The state of the project, as the room hands it to LainOS.
 *
 * These tests are about the three promises the briefing makes and not about
 * its wording: it reads the caches the lenses render, it never turns something
 * unreadable into a zero, and every figure it prints is in the unit an
 * operator says it in.
 */
beforeEach(function () {
    Cache::flush();
    // This box's .env carries a real station, and the queue would otherwise
    // grow a tank row in every one of these tests. The two that are about the
    // station bind their own.
    config()->set('wallet.sponsor.enabled', false);
    config()->set('wallet.sponsor.station', null);
    config()->set('monitoring.services', [
        'cyberia-rpc' => [
            'group' => 'chain',
            'label' => 'Cyberia RPC',
            'critical' => true,
            'check' => ['type' => 'chain', 'url' => 'https://rpc.example'],
        ],
        'site' => [
            'group' => 'web',
            'label' => 'cyberia.church',
            'check' => ['type' => 'http', 'url' => 'https://example.com'],
        ],
    ]);
});

function briefingText(): string
{
    ConsoleBriefing::forget();
    ConsoleFeed::forget();

    return app(ConsoleBriefing::class)->toText();
}

it('reads the chain out of the sweep instead of asking the node again', function () {
    ServiceCheck::create([
        'service' => 'cyberia-rpc',
        'status' => 'up',
        'latency_ms' => 42,
        'detail' => ['block' => 4815162, 'head_age_seconds' => 3],
        'checked_at' => now(),
    ]);

    $text = briefingText();

    expect($text)->toContain('4815162')
        ->and($text)->toContain('42 мс')
        // A chat message must not fan out into RPC calls: the head is the one
        // the monitor already fetched, and it is dated so nobody reads a
        // twelve-hour-old number as "now".
        ->and($text)->toContain('назад');
});

it('says the chain is unknown rather than reporting block zero', function () {
    $text = briefingText();

    expect($text)->toContain('состояние цепи неизвестно')
        ->and($text)->not->toContain('блок 0');
});

it('prints the gas tank in CYBER, not in wei', function () {
    // The station answers in wei — every one of its amounts. A briefing that
    // printed the integer would say the tank holds a billion billion.
    $station = Mockery::mock(GasSponsorService::class)->makePartial();
    $station->shouldReceive('enabled')->andReturnTrue();
    $station->shouldReceive('summary')->andReturn([
        'tank' => '990000000000000000',
        'drip' => '10000000000000000',
        'ceiling' => '0',
        'cooldown' => 86400,
        'dailyCap' => '5000000000000000000',
        'remainingToday' => '2500000000000000000',
        'served' => 7,
        'spent' => '0',
        'paused' => false,
    ]);
    app()->instance(GasSponsorService::class, $station);

    $text = briefingText();

    expect($text)->toContain('в баке 0.99 CYBER')
        ->and($text)->toContain('99 заправок')
        ->and($text)->toContain('2.5 из 5 CYBER')
        ->and($text)->not->toContain('990000000000000000');
});

it('distinguishes an unreadable station from an empty one', function () {
    $station = Mockery::mock(GasSponsorService::class)->makePartial();
    $station->shouldReceive('enabled')->andReturnTrue();
    $station->shouldReceive('summary')->andReturnNull();
    app()->instance(GasSponsorService::class, $station);

    expect(briefingText())->toContain('не прочитан');
});

it('carries the queue the console itself is showing', function () {
    ServiceIncident::create([
        'service' => 'cyberia-rpc',
        'status' => 'down',
        'reason' => 'stale-head',
        'started_at' => now()->subHours(3),
    ]);

    $text = briefingText();

    expect($text)->toContain('Требуют человека: 1')
        ->and($text)->toContain('Cyberia RPC')
        ->and($text)->toContain('stale-head')
        // Time in state is the priority on every lens, and it is the one thing
        // a status line without it cannot say.
        ->and($text)->toContain('3 ч');
});

it('says the queue is empty in words, with when the last sweep ran', function () {
    ServiceCheck::create([
        'service' => 'site',
        'status' => 'up',
        'latency_ms' => 100,
        'detail' => [],
        'checked_at' => now()->subMinutes(4),
    ]);

    $text = briefingText();

    expect($text)->toContain('Пусто')
        ->and($text)->toContain('Последний обход');
});

it('carries the board and the base', function () {
    CrmTask::create([
        'title' => 'позвонить бирже',
        'status' => 'open',
        'priority' => 'normal',
        'due_at' => now()->addDay(),
    ]);
    CrmContact::create(['name' => 'кит', 'type' => 'whale', 'status' => 'new']);

    $text = briefingText();

    expect($text)->toContain('Задачи: активных 1')
        ->and($text)->toContain('whale 1');
});
