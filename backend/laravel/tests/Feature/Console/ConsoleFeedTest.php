<?php

use App\Models\ConsoleSnooze;
use App\Models\CrmContact;
use App\Models\CrmTask;
use App\Models\ServiceCheck;
use App\Models\ServiceIncident;
use App\Models\User;
use App\Services\Console\ConsoleFeed;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Сейчас" — the queue.
 *
 * The tests worth having here are about ordering and about silence, because
 * those are the two things that decide whether the console gets read: a row
 * that cannot be put down teaches the whole list to be ignored, and an empty
 * screen that says nothing is indistinguishable from a broken collector.
 */
beforeEach(function () {
    $this->withoutVite();

    config()->set('crm.admin_wallets', ['0x00000000000000000000000000000000000000aa']);
    config()->set('crm.console.cache_seconds', 0);
    config()->set('monitoring.services', [
        'queue' => [
            'group' => 'infra',
            'label' => 'Queue',
            'critical' => true,
            'check' => ['type' => 'none'],
            'usage' => null,
        ],
    ]);

    ConsoleFeed::forget();
});

function consoleOperator(): User
{
    return User::factory()->create([
        'wallet_address' => '0x00000000000000000000000000000000000000aa',
    ]);
}

it('is a 404 for everyone who is not an operator', function () {
    $this->get('/crm')->assertRedirect();
    $this->actingAs(User::factory()->create())->get('/crm')->assertNotFound();
});

it('puts an open incident and an overdue promise in the same queue', function () {
    ServiceIncident::create([
        'service' => 'queue',
        'status' => 'down',
        'reason' => 'no-response',
        'started_at' => now()->subMinutes(12),
    ]);

    CrmTask::factory()->standalone()->create([
        'title' => 'Check the relayer nonce race',
        'priority' => 'high',
        'due_at' => now()->subDays(2),
    ]);

    $this->actingAs(consoleOperator())
        ->get('/crm')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Now')
            ->has('attention', 2)
            // Both are critical, so the freshest state change leads: a
            // two-day-old task already has somebody on it, a twelve-minute
            // outage does not.
            ->where('attention.0.kind', 'incident')
            ->where('attention.0.severity', 'critical')
            ->where('attention.1.kind', 'task')
            // The duration column is the priority, so it travels with the row.
            ->where('attention.0.duration_seconds', fn (int $seconds) => $seconds >= 700)
            ->where('quiet.is_quiet', false)
        );
});

it('sorts a warning below a failure and money below both', function () {
    ServiceIncident::create([
        'service' => 'queue',
        'status' => 'degraded',
        'reason' => 'slow',
        'started_at' => now()->subHours(3),
    ]);

    CrmContact::factory()->whale()->create(['name' => 'Nakamoto Ghost']);

    CrmTask::factory()->standalone()->create([
        'title' => 'Answer the whale',
        'priority' => 'high',
        'due_at' => now()->subDay(),
    ]);

    $this->actingAs(consoleOperator())
        ->get('/crm')
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.severity', 'critical')
            ->where('attention.1.severity', 'warning')
            ->where('attention.2.severity', 'money')
            ->where('attention.2.kind', 'whale')
        );
});

it('moves a snoozed row into the watch list with its wake-up time', function () {
    ServiceIncident::create([
        'service' => 'queue',
        'status' => 'down',
        'reason' => 'no-response',
        'started_at' => now()->subMinutes(20),
    ]);

    $operator = consoleOperator();

    $this->actingAs($operator)
        ->post('/crm/snooze', ['key' => 'incident:queue'])
        ->assertRedirect();

    expect(ConsoleSnooze::query()->where('key', 'incident:queue')->exists())->toBeTrue();

    $this->actingAs($operator)
        ->get('/crm')
        ->assertInertia(fn (Assert $page) => $page
            ->has('attention', 0)
            // Nothing ever disappears: the row is still on the page, with the
            // hour it comes back.
            ->where('watch.0.key', 'snoozed')
            ->has('watch.0.items', 1)
            ->where('watch.0.items.0.snoozed_until', fn (?string $at) => $at !== null)
        );

    $this->actingAs($operator)
        ->delete('/crm/snooze', ['key' => 'incident:queue'])
        ->assertRedirect();

    $this->actingAs($operator)
        ->get('/crm')
        ->assertInertia(fn (Assert $page) => $page->has('attention', 1));
});

it('says how long it has been quiet and when the last sweep ran', function () {
    ServiceCheck::create([
        'service' => 'queue',
        'status' => 'up',
        'latency_ms' => 12,
        'detail' => [],
        'checked_at' => now()->subMinute(),
    ]);

    ServiceIncident::create([
        'service' => 'queue',
        'status' => 'down',
        'reason' => 'no-response',
        'started_at' => now()->subHours(8),
        'resolved_at' => now()->subHours(4),
    ]);

    $this->actingAs(consoleOperator())
        ->get('/crm')
        ->assertInertia(fn (Assert $page) => $page
            ->where('quiet.is_quiet', true)
            // Without these three an empty screen and a dead collector look
            // exactly the same.
            ->where('quiet.answered', 1)
            ->where('quiet.registered', 1)
            ->where('quiet.last_sweep', fn (?string $at) => $at !== null)
            ->where('quiet.since', fn (?string $at) => $at !== null)
        );
});

it('carries thirty days of background on every load', function () {
    $this->actingAs(consoleOperator())
        ->get('/crm')
        ->assertInertia(fn (Assert $page) => $page
            ->has('tiles', 6)
            ->where('tiles.0.key', 'funded_active')
            ->where('tiles.4.key', 'services')
        );
});
