<?php

use App\Models\Activity;
use App\Models\Dao;
use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\User;
use App\Notifications\DaoActivityNotification;

/**
 * Only the DAO's own notifications. Acting in a DAO is also activity, so the
 * actor may collect a gamification notice in the same request — counting every
 * unread row made these assertions quietly depend on the quest board.
 */
function daoUnread(User $user): int
{
    return $user->fresh()->unreadNotifications()
        ->where('type', DaoActivityNotification::class)
        ->count();
}

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
        'ends_at' => now()->addWeek()->toIso8601String(),
    ]);

    expect(daoUnread($participant))->toBe(1)
        ->and(daoUnread($author))->toBe(0);

    $data = $participant->fresh()->unreadNotifications()
        ->where('type', DaoActivityNotification::class)->first()->data;
    expect($data['type'])->toBe('proposal.created');
});

test('a comment notifies the proposal author', function () {
    $author = User::factory()->create();
    $commenter = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $author->id]);

    $this->actingAs($commenter)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'ping',
    ]);

    expect(daoUnread($author))->toBe(1);
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

    expect(daoUnread($parentAuthor))->toBe(1)
        ->and(daoUnread($proposalAuthor))->toBe(1)
        ->and(daoUnread($replier))->toBe(0);
});

test('a vote notifies the proposal author', function () {
    $author = User::factory()->create();
    $voter = User::factory()->create();
    $proposal = Proposal::factory()->open()->create(['user_id' => $author->id]);

    $this->actingAs($voter)->post("/proposals/{$proposal->id}/votes", [
        'wallet_address' => '0x1234567890abcdef1234567890abcdef12345678',
        'support' => true,
    ]);

    expect(daoUnread($author))->toBe(1);
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

    expect(daoUnread($author))->toBe(1)
        ->and(daoUnread($reactor))->toBe(0);
});
