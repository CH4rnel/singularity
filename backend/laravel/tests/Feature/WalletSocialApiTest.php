<?php

use App\Models\Activity;
use App\Models\Dao;
use App\Models\Post;
use App\Models\Proposal;
use App\Models\ProposalVote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * What the wallet reads about the rest of Cyberia.
 *
 * The wallet has no session — the seed lives in the browser and this server
 * never learns whose it is — so these endpoints have to answer without one.
 * That is the property worth pinning: a signed-out request gets the same data a
 * signed-in one does, and nothing here can be written to.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Responses are cached for the wallet's refresh cadence; a test that wrote
    // rows and then read a neighbour's cache entry would pass for the wrong
    // reason.
    Cache::flush();
});

it('serves the feed to nobody in particular', function () {
    $author = User::factory()->create(['name' => 'ghostline']);
    Post::factory()->create(['user_id' => $author->id, 'body' => 'the wired is quiet']);

    $response = $this->getJson('/api/wallet/feed');

    $response->assertOk();
    expect($response->json('items'))->toHaveCount(1);
    expect($response->json('items.0.kind'))->toBe('post');
    expect($response->json('items.0.text'))->toBe('the wired is quiet');
    expect($response->json('items.0.who.name'))->toBe('ghostline');
});

it('merges DAO activity into the same stream, newest first', function () {
    $author = User::factory()->create();
    $dao = Dao::factory()->create(['name' => 'Noosphere']);
    $proposal = Proposal::factory()->create([
        'dao_id' => $dao->id,
        'user_id' => $author->id,
        'title' => 'Fund the relay set',
    ]);

    Post::factory()->create([
        'user_id' => $author->id,
        'created_at' => now()->subHour(),
    ]);
    Activity::factory()->create([
        'type' => 'proposal.created',
        'user_id' => $author->id,
        'dao_id' => $dao->id,
        'subject_type' => Proposal::class,
        'subject_id' => $proposal->id,
        'created_at' => now(),
    ]);

    $items = $this->getJson('/api/wallet/feed')->assertOk()->json('items');

    expect($items)->toHaveCount(2);
    expect($items[0]['kind'])->toBe('dao');
    expect($items[0]['type'])->toBe('proposal.created');
    // The type stays a key so the wallet can say it in either language; the
    // title travels beside it because a translated key alone says nothing.
    expect($items[0]['text'])->toBe('Fund the relay set');
    expect($items[1]['kind'])->toBe('post');
});

it('filters the feed down to one source', function () {
    $author = User::factory()->create();
    $dao = Dao::factory()->create();
    $proposal = Proposal::factory()->create(['dao_id' => $dao->id, 'user_id' => $author->id]);

    Post::factory()->create(['user_id' => $author->id]);
    Activity::factory()->create([
        'type' => 'proposal.created',
        'user_id' => $author->id,
        'dao_id' => $dao->id,
        'subject_type' => Proposal::class,
        'subject_id' => $proposal->id,
    ]);

    expect($this->getJson('/api/wallet/feed?tab=posts')->json('items'))
        ->toHaveCount(1)
        ->and($this->getJson('/api/wallet/feed?tab=posts')->json('items.0.kind'))
        ->toBe('post');

    Cache::flush();

    expect($this->getJson('/api/wallet/feed?tab=dao')->json('items.0.kind'))
        ->toBe('dao');
});

it('tallies a proposal by voting power, not by voter count', function () {
    $dao = Dao::factory()->create(['name' => 'Noosphere']);
    $proposal = Proposal::factory()->create([
        'dao_id' => $dao->id,
        'title' => 'Lower the launchpad fee',
        'ends_at' => now()->addDay(),
    ]);

    // Two small votes for, one large vote against: counting voters would call
    // this proposal passing, and counting power says the opposite.
    ProposalVote::factory()->count(2)->create([
        'proposal_id' => $proposal->id,
        'support' => true,
        'voting_power' => '10',
    ]);
    ProposalVote::factory()->create([
        'proposal_id' => $proposal->id,
        'support' => false,
        'voting_power' => '100',
    ]);

    $body = $this->getJson('/api/wallet/dao')->assertOk()->json();

    expect($body['daos'][0]['name'])->toBe('Noosphere');
    expect($body['proposals'][0]['title'])->toBe('Lower the launchpad fee');
    expect($body['proposals'][0]['votes'])->toBe(3);
    expect((float) $body['proposals'][0]['powerFor'])->toBe(20.0);
    expect((float) $body['proposals'][0]['powerAgainst'])->toBe(100.0);
    expect($body['proposals'][0]['status'])->toBe('open');

    $detail = $this->getJson("/api/wallet/dao/proposals/{$proposal->id}")
        ->assertOk()
        ->json('proposal');

    expect($detail['id'])->toBe($proposal->id);
    expect($detail)->toHaveKey('descriptionHtml');
});

it('answers for an address nobody here has claimed', function () {
    $address = '0x'.str_repeat('ab', 20);

    $body = $this->getJson("/api/wallet/profile/{$address}")->assertOk()->json();

    // Not an error: the wallet knows the key it holds and nothing about who
    // signed up on this server, so "unclaimed" is a real answer.
    expect($body['claimed'])->toBeFalse();
    expect($body['address'])->toBe($address);
    expect($body['achievements'])->not->toBeEmpty();
    expect(collect($body['achievements'])->pluck('earned')->unique()->all())->toBe([false]);
});

it('answers for a claimed address without leaking an account', function () {
    $user = User::factory()->create([
        'name' => 'ghostline',
        'wallet_address' => '0x'.str_repeat('cd', 20),
        'email' => 'ghost@example.test',
    ]);
    $dao = Dao::factory()->create();
    Proposal::factory()->create(['dao_id' => $dao->id, 'user_id' => $user->id]);

    // Looked up case-insensitively: the wallet checksums its own addresses and
    // the database holds whatever was stored when the account was linked.
    $body = $this->getJson('/api/wallet/profile/0x'.strtoupper(substr($user->wallet_address, 2)))
        ->assertOk()
        ->json();

    expect($body['claimed'])->toBeTrue();
    expect($body['name'])->toBe('ghostline');
    expect($body['stats']['proposals'])->toBe(1);
    expect($body)->not->toHaveKey('email');
});

it('refuses anything that is not an address', function () {
    $this->getJson('/api/wallet/profile/not-an-address')->assertStatus(422);
    $this->getJson('/api/wallet/profile/0x123')->assertStatus(422);
});
