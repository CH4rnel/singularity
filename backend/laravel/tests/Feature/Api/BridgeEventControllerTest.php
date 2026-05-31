<?php

use App\Models\BridgeEvent;
use App\Models\User;
use Illuminate\Support\Str;

test('stores a valid bridge event', function () {
    $sessionId = (string) Str::uuid();

    $response = $this->postJson('/api/bridge/events', [
        'session_id' => $sessionId,
        'event_type' => 'page_view',
    ]);

    $response->assertStatus(202)
        ->assertJson(['ok' => true]);

    expect(BridgeEvent::count())->toBe(1);
    expect(BridgeEvent::first()->session_id)->toBe($sessionId);
    expect(BridgeEvent::first()->event_type)->toBe('page_view');
});

test('stores ip and user agent', function () {
    $sessionId = (string) Str::uuid();

    $this->withServerVariables(['HTTP_USER_AGENT' => 'TestAgent/1.0'])
        ->postJson('/api/bridge/events', [
            'session_id' => $sessionId,
            'event_type' => 'direction_selected',
            'direction' => 'evm_to_sol',
        ])
        ->assertStatus(202);

    $event = BridgeEvent::first();
    expect($event->ip)->not->toBeNull();
    expect($event->user_agent)->toBe('TestAgent/1.0');
    expect($event->direction)->toBe('evm_to_sol');
});

test('attaches user id when authenticated', function () {
    $user = User::factory()->create();
    $sessionId = (string) Str::uuid();

    $this->actingAs($user)
        ->postJson('/api/bridge/events', [
            'session_id' => $sessionId,
            'event_type' => 'bridge_submitted',
        ])
        ->assertStatus(202);

    expect(BridgeEvent::first()->user_id)->toBe($user->id);
});

test('rejects unknown event_type', function () {
    $this->postJson('/api/bridge/events', [
        'session_id' => (string) Str::uuid(),
        'event_type' => 'totally_made_up',
    ])->assertStatus(422);

    expect(BridgeEvent::count())->toBe(0);
});

test('rejects non-uuid session_id', function () {
    $this->postJson('/api/bridge/events', [
        'session_id' => 'not-a-uuid',
        'event_type' => 'page_view',
    ])->assertStatus(422);
});

test('rejects missing required fields', function () {
    $this->postJson('/api/bridge/events', [])->assertStatus(422);
});

test('accepts optional payload fields', function () {
    $sessionId = (string) Str::uuid();

    $this->postJson('/api/bridge/events', [
        'session_id' => $sessionId,
        'event_type' => 'lock_tx_confirmed',
        'direction' => 'sol_to_evm',
        'amount' => '12.5',
        'source_address' => 'AbCdEfGh',
        'destination_address' => '0x1234567890123456789012345678901234567890',
        'metadata' => ['tx_hash' => '0xdead', 'nonce' => 7],
    ])->assertStatus(202);

    $event = BridgeEvent::first();
    expect((string) $event->amount)->toContain('12.5');
    expect($event->metadata)->toBe(['tx_hash' => '0xdead', 'nonce' => 7]);
});
