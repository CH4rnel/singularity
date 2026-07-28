<?php

use App\Models\SiteEvent;
use App\Models\User;
use App\Services\BridgeEventLogger;
use Inertia\Testing\AssertableInertia as Assert;

test('site events are ingested for guests without csrf', function () {
    $sessionId = (string) Str::uuid();

    $this->postJson('/api/events', [
        'session_id' => $sessionId,
        'event' => 'landing_view',
        'page' => '/',
        'metadata' => [
            'source' => 'ash',
            'medium' => 'social',
            'campaign' => 'cyberia_partner_launch_2026q3',
        ],
    ])->assertStatus(202);

    expect(SiteEvent::count())->toBe(1)
        ->and(SiteEvent::first()->user_id)->toBeNull()
        ->and(SiteEvent::first()->metadata)->toBe([
            'source' => 'ash',
            'medium' => 'social',
            'campaign' => 'cyberia_partner_launch_2026q3',
        ]);
});

test('site events attach the logged-in user and reject unknown events', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/events', [
        'session_id' => (string) Str::uuid(),
        'event' => 'wallet_connected',
        'wallet_address' => '0x0000000000000000000000000000000000000001',
    ])->assertStatus(202);

    expect(SiteEvent::first()->user_id)->toBe($user->id)
        ->and(SiteEvent::first()->wallet_address)->toBeNull();

    $this->postJson('/api/events', [
        'session_id' => (string) Str::uuid(),
        'event' => 'totally_made_up',
    ])->assertStatus(422);
});

test('the complete funnel event vocabulary accepts only safe metadata', function (string $event) {
    $this->postJson('/api/events', [
        'session_id' => (string) Str::uuid(),
        'event' => $event,
        'page' => '/robinhood-chain',
        'metadata' => [
            'source' => 'partner_x',
            'medium' => 'social',
            'campaign' => 'robinhood_launch',
            'partner' => 'ash',
            'network' => 'Robinhood Chain',
            'token' => 'ASH',
            'action_type' => 'trade',
        ],
    ])->assertStatus(202);
})->with([
    'landing_view',
    'wallet_connect_started',
    'wallet_connected',
    'network_switch',
    'bridge_started',
    'bridge_completed',
    'swap_started',
    'swap_completed',
    'staking_started',
    'staking_completed',
    'partner_cta_clicked',
]);

test('site events reject metadata outside the safe attribution vocabulary', function () {
    $this->postJson('/api/events', [
        'session_id' => (string) Str::uuid(),
        'event' => 'partner_cta_clicked',
        'metadata' => [
            'partner' => 'ash',
            'signature' => 'must-not-be-collected',
        ],
    ])->assertJsonValidationErrors('metadata');

    expect(SiteEvent::count())->toBe(0);
});

test('the analytics page builds the funnel from unique sessions', function () {
    $user = User::factory()->crmAdmin()->create();

    $visitorA = (string) Str::uuid();
    $visitorB = (string) Str::uuid();

    // Visitor A: views twice (counted once), connects, adds liquidity.
    foreach (['landing_view', 'page_view', 'wallet_connected', 'swap_completed', 'liquidity_added'] as $event) {
        SiteEvent::create([
            'session_id' => $visitorA,
            'event' => $event,
            'page' => '/liquidity',
            'created_at' => now(),
        ]);
    }
    // Visitor B: bounce.
    SiteEvent::create([
        'session_id' => $visitorB,
        'event' => 'page_view',
        'page' => '/',
        'created_at' => now(),
    ]);

    // Bridge submission from visitor A's session.
    app(BridgeEventLogger::class)->log('bridge_submitted', [
        'session_id' => $visitorA,
        'direction' => 'sol_to_evm',
    ]);

    $this->actingAs($user)
        ->get('/crm/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Analytics')
            ->where('funnel.visitors', 2)
            ->where('funnel.wallets', 1)
            ->where('funnel.liquidity', 1)
            ->where('funnel.swaps', 1)
            ->where('funnel.bridge', 1)
            ->has('daily', 1)
            ->has('recent', 6)
        );
});

test('guests cannot open the analytics page', function () {
    $this->get('/crm/analytics')->assertRedirect(route('login'));
});
