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

test('the numbers lens builds the session funnel from unique sessions', function () {
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
        ->get('/crm/numbers?subject=sessions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('crm/Numbers')
            ->where('subject', 'sessions')
            // Six questions, always the same six, so a subject that cannot
            // answer one says so instead of dropping the block.
            ->has('questions', 6)
            ->where('questions.0.key', 'growth')
            ->where('questions.0.answer.value', 2)
            ->where('questions.1.evidence.steps.1.value', 1)
            ->where('questions.1.evidence.steps.3.value', 1)
            ->where('questions.1.evidence.steps.4.value', 1)
            ->where('questions.3.evidence.type', 'unmeasured')
            ->where('questions.5.evidence.type', 'unmeasured')
        );
});

test('the old analytics address still opens the lens that answers it', function () {
    $user = User::factory()->crmAdmin()->create();

    $this->actingAs($user)
        ->get('/crm/analytics')
        ->assertRedirect('/crm/numbers?subject=sessions');

    $this->actingAs($user)->get('/crm/product')->assertRedirect('/crm/numbers');
    $this->actingAs($user)->get('/crm/services')->assertRedirect('/crm/machines');
});

test('guests cannot open the numbers lens', function () {
    $this->get('/crm/numbers')->assertRedirect(route('login'));
});
