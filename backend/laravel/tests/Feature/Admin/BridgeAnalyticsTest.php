<?php

use App\Models\BridgeEvent;
use App\Models\BridgeRequest;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('app.env', 'testing');
});

test('guests are redirected from analytics dashboard', function () {
    $this->get('/admin/bridge-analytics')->assertRedirect();
});

test('non-allowlisted user sees 404', function () {
    $user = User::factory()->create(['email' => 'random@example.com']);

    $this->actingAs($user)
        ->get('/admin/bridge-analytics')
        ->assertNotFound();
});

test('allowlisted user sees dashboard', function () {
    $admin = User::factory()->create(['email' => 'admin@example.com']);
    config()->set('bridge.admin_emails', ['admin@example.com']);

    $this->actingAs($admin)
        ->get('/admin/bridge-analytics')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/BridgeAnalytics')
                ->has('funnel')
                ->has('totals')
        );

});

test('funnel counts distinct sessions per event', function () {
    $admin = User::factory()->create(['email' => 'admin2@example.com']);
    config()->set('bridge.admin_emails', ['admin2@example.com']);

    $sessionA = (string) Str::uuid();
    $sessionB = (string) Str::uuid();

    BridgeEvent::create(['session_id' => $sessionA, 'event_type' => 'page_view']);
    BridgeEvent::create(['session_id' => $sessionA, 'event_type' => 'page_view']); // duplicate session
    BridgeEvent::create(['session_id' => $sessionB, 'event_type' => 'page_view']);
    BridgeEvent::create(['session_id' => $sessionA, 'event_type' => 'direction_selected']);

    $this->actingAs($admin)
        ->get('/admin/bridge-analytics')
        ->assertInertia(function ($page) {
            $funnel = collect($page->toArray()['props']['funnel']);
            $pageView = $funnel->firstWhere('event_type', 'page_view');
            $direction = $funnel->firstWhere('event_type', 'direction_selected');

            expect($pageView['sessions'])->toBe(2);
            expect($pageView['total'])->toBe(3);
            expect($direction['sessions'])->toBe(1);

            return $page;
        });

});

test('totals reflect bridge requests', function () {
    $admin = User::factory()->create(['email' => 'admin3@example.com']);
    config()->set('bridge.admin_emails', ['admin3@example.com']);

    BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xaaa',
        'source_nonce' => 1,
        'sender_address' => '0xsender',
        'recipient_address' => 'AbCd',
        'amount' => '1.0',
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    BridgeRequest::create([
        'direction' => 'evm_to_sol',
        'source_chain' => 'cyberia',
        'source_tx_hash' => '0xbbb',
        'source_nonce' => 2,
        'sender_address' => '0xsender',
        'recipient_address' => 'AbCd',
        'amount' => '2.0',
        'status' => 'failed',
    ]);

    $this->actingAs($admin)
        ->get('/admin/bridge-analytics')
        ->assertInertia(function ($page) {
            $totals = $page->toArray()['props']['totals'];

            expect($totals['requests'])->toBe(2);
            expect($totals['completed'])->toBe(1);
            expect($totals['failed'])->toBe(1);

            return $page;
        });

});
