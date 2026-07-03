<?php

use App\Models\Activity;
use App\Models\Dao;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\ProposalVote;
use App\Models\User;

test('creating a proposal records a feed activity', function () {
    $user = User::factory()->create();
    $dao = Dao::factory()->create();

    $this->actingAs($user)->post("/dao/{$dao->id}/proposals", [
        'dao_id' => $dao->id,
        'title' => 'Feed me',
    ]);

    $this->assertDatabaseHas('activities', [
        'type' => 'proposal.created',
        'user_id' => $user->id,
        'dao_id' => $dao->id,
        'subject_type' => Proposal::class,
    ]);
});

test('posting a comment records a feed activity', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'hello feed',
    ]);

    $this->assertDatabaseHas('activities', [
        'type' => 'comment.posted',
        'user_id' => $user->id,
        'subject_type' => ProposalComment::class,
    ]);
});

test('casting a vote records a feed activity once', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->open()->create();

    $payload = [
        'wallet_address' => '0x1234567890abcdef1234567890abcdef12345678',
        'support' => true,
    ];

    $this->actingAs($user)->post("/proposals/{$proposal->id}/votes", $payload);
    // Re-vote must not add a second feed entry.
    $this->actingAs($user)->post("/proposals/{$proposal->id}/votes", [
        ...$payload,
        'support' => false,
    ]);

    expect(Activity::where('type', 'vote.cast')->count())->toBe(1);
});

test('dao index returns a paginated feed', function () {
    Activity::factory()->count(25)->create();

    $response = $this->get('/dao');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dao/Index')
            ->has('activities.data', 20)
            ->has('daos'));
});

test('deleting a proposal purges its feed entries', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $user->id]);
    $comment = ProposalComment::factory()->create(['proposal_id' => $proposal->id]);
    $vote = ProposalVote::factory()->create(['proposal_id' => $proposal->id]);

    Activity::factory()->create([
        'subject_type' => Proposal::class,
        'subject_id' => $proposal->id,
    ]);
    Activity::factory()->create([
        'type' => 'comment.posted',
        'subject_type' => ProposalComment::class,
        'subject_id' => $comment->id,
    ]);
    Activity::factory()->create([
        'type' => 'vote.cast',
        'subject_type' => ProposalVote::class,
        'subject_id' => $vote->id,
    ]);

    $this->actingAs($user)->delete("/proposals/{$proposal->id}");

    expect(Activity::count())->toBe(0);
});
