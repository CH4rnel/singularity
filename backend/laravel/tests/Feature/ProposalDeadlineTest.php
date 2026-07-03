<?php

use App\Models\Proposal;
use App\Models\User;

test('status is open while deadline is in the future', function () {
    $proposal = Proposal::factory()->open()->create();

    expect($proposal->status)->toBe('open')
        ->and($proposal->isOpen())->toBeTrue();
});

test('status is closed after the deadline', function () {
    $proposal = Proposal::factory()->closed()->create();

    expect($proposal->status)->toBe('closed')
        ->and($proposal->isOpen())->toBeFalse();
});

test('proposals without a deadline stay open', function () {
    $proposal = Proposal::factory()->create(['ends_at' => null]);

    expect($proposal->status)->toBe('open');
});

test('voting on a closed proposal is forbidden', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->closed()->create();

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/votes", [
        'wallet_address' => '0x1234567890abcdef1234567890abcdef12345678',
        'support' => true,
    ]);

    $response->assertForbidden();
    $this->assertDatabaseCount('proposal_votes', 0);
});

test('commenting stays allowed after the deadline', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->closed()->create();

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'Post-mortem discussion',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('proposal_comments', ['proposal_id' => $proposal->id]);
});
