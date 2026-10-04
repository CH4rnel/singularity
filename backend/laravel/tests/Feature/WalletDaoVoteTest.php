<?php

use App\Models\Dao;
use App\Models\Proposal;
use App\Models\User;

/**
 * Voting on a proposal from inside the wallet.
 *
 * The wallet signs the site's login challenge and then votes as that session,
 * so what is pinned here is who a vote is cast *as*: always the signed-in
 * address, never one named in the request — and a browser signed in as
 * somebody else is refused rather than voting under the wrong name.
 */
const WALLET_VOTER = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

it('refuses a vote from a wallet that has not signed in', function () {
    $proposal = Proposal::factory()->create();

    $this->postJson("/api/wallet/dao/proposals/{$proposal->id}/vote", [
        'address' => WALLET_VOTER,
        'support' => true,
    ])->assertUnauthorized();

    expect($proposal->votes()->count())->toBe(0);
});

it('casts and then changes a vote as the signed-in address', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $proposal = Proposal::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/wallet/dao/proposals/{$proposal->id}/vote", [
            'address' => '0x'.strtoupper(substr(WALLET_VOTER, 2)),
            'support' => true,
        ])
        ->assertOk()
        ->assertJsonPath('vote.support', true);

    $this->actingAs($user)
        ->postJson("/api/wallet/dao/proposals/{$proposal->id}/vote", [
            'address' => WALLET_VOTER,
            'support' => false,
        ])
        ->assertOk()
        ->assertJsonPath('vote.support', false);

    expect($proposal->votes()->count())->toBe(1)
        ->and($proposal->votes()->first()->wallet_address)->toBe(WALLET_VOTER);

    $this->actingAs($user)
        ->getJson("/api/wallet/dao/proposals/{$proposal->id}/vote")
        ->assertOk()
        ->assertJsonPath('vote.support', false);
});

it('refuses a session that belongs to a different wallet', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $proposal = Proposal::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/wallet/dao/proposals/{$proposal->id}/vote", [
            'address' => '0x'.str_repeat('b', 40),
            'support' => true,
        ])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'otherAccount');

    expect($proposal->votes()->count())->toBe(0);
});

it('refuses a vote on a closed proposal', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $proposal = Proposal::factory()->create();
    $proposal->forceFill(['ends_at' => now()->subDay()])->saveQuietly();

    $this->actingAs($user)
        ->postJson("/api/wallet/dao/proposals/{$proposal->id}/vote", [
            'address' => WALLET_VOTER,
            'support' => true,
        ])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'closed');

    expect($proposal->votes()->count())->toBe(0);
});

it('answers null for an account that has not voted', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $proposal = Proposal::factory()->create();

    $this->actingAs($user)
        ->getJson("/api/wallet/dao/proposals/{$proposal->id}/vote")
        ->assertOk()
        ->assertJsonPath('vote', null);
});

it('puts a proposal up as the signed-in address', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $dao = Dao::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/wallet/dao/proposals', [
        'address' => WALLET_VOTER,
        'dao_id' => $dao->id,
        'title' => 'Ship voting to the phone',
        'description' => 'Because nobody found it.',
        'ends_at' => now()->addDays(7)->toIso8601String(),
    ])->assertCreated();

    $proposal = Proposal::query()->findOrFail($response->json('id'));

    expect($proposal->user_id)->toBe($user->id)
        ->and($proposal->dao_id)->toBe($dao->id)
        ->and($proposal->status)->toBe('open');
});

it('refuses a proposal from a session that belongs to a different wallet', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $dao = Dao::factory()->create();

    $this->actingAs($user)->postJson('/api/wallet/dao/proposals', [
        'address' => '0x'.str_repeat('b', 40),
        'dao_id' => $dao->id,
        'title' => 'Under a borrowed name',
        'ends_at' => now()->addDay()->toIso8601String(),
    ])->assertStatus(409);

    expect(Proposal::query()->count())->toBe(0);
});

it('refuses a proposal with no deadline or one in the past', function () {
    $user = User::factory()->create(['wallet_address' => WALLET_VOTER]);
    $dao = Dao::factory()->create();

    $this->actingAs($user)->postJson('/api/wallet/dao/proposals', [
        'address' => WALLET_VOTER,
        'dao_id' => $dao->id,
        'title' => 'Already over',
        'ends_at' => now()->subDay()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('ends_at');
});

it('refuses a proposal from a wallet that has not signed in', function () {
    $dao = Dao::factory()->create();

    $this->postJson('/api/wallet/dao/proposals', [
        'address' => WALLET_VOTER,
        'dao_id' => $dao->id,
        'title' => 'Anonymous',
        'ends_at' => now()->addDay()->toIso8601String(),
    ])->assertUnauthorized();
});
