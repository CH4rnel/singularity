<?php

use App\Models\Activity;
use App\Models\Dao;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\User;

test('a new proposal notifies prior dao participants but not the author', function () {
    $author = User::factory()->create();
    $participant = User::factory()->create();
    $dao = Dao::factory()->create();

    // The participant acted in this DAO before.
    Activity::factory()->create([
        'user_id' => $participant->id,
        'dao_id' => $dao->id,
    ]);

    $this->actingAs($author)->post("/dao/{$dao->id}/proposals", [
        'dao_id' => $dao->id,
        'title' => 'Notify me',
    ]);

    expect($participant->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($author->fresh()->unreadNotifications()->count())->toBe(0);

    $data = $participant->fresh()->unreadNotifications()->first()->data;
    expect($data['type'])->toBe('proposal.created');
});

test('a comment notifies the proposal author', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $author->id]);

    $this->actingAs($commenter)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'ping',
    ]);

    expect($author->fresh()->unreadNotifications()->count())->toBe(1);
});

test('a reply notifies the parent comment author too', function () {
    $proposalAuthor = User::factory()->create();
    $parentAuthor = User::factory()->create();
    $replier = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $proposalAuthor->id]);
    $parent = ProposalComment::factory()->create([
        'proposal_id' => $proposal->id,
        'user_id' => $parentAuthor->id,
    ]);

    $this->actingAs($replier)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'reply',
        'parent_id' => $parent->id,
    ]);

    expect($parentAuthor->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($proposalAuthor->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($replier->fresh()->unreadNotifications()->count())->toBe(0);
});

test('a vote notifies the proposal author', function () {
    $author = User::factory()->create();
    $voter = User::factory()->create();
    $proposal = Proposal::factory()->open()->create(['user_id' => $author->id]);

    $this->actingAs($voter)->post("/proposals/{$proposal->id}/votes", [
        'wallet_address' => '0x1234567890abcdef1234567890abcdef12345678',
        'support' => true,
    ]);

    expect($author->fresh()->unreadNotifications()->count())->toBe(1);
});

test('a reaction notifies the content author but not self-reactions', function () {
    $author = User::factory()->create();
    $reactor = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $author->id]);

    $this->actingAs($reactor)->post('/reactions', [
        'reactable_type' => 'proposal',
        'reactable_id' => $proposal->id,
        'emoji' => '🔥',
    ]);
    // Self-reaction must not notify.
    $this->actingAs($author)->post('/reactions', [
        'reactable_type' => 'proposal',
        'reactable_id' => $proposal->id,
        'emoji' => '👍',
    ]);

    expect($author->fresh()->unreadNotifications()->count())->toBe(1)
        ->and($reactor->fresh()->unreadNotifications()->count())->toBe(0);
});
