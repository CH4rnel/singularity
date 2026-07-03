<?php

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\User;

test('users can reply to a top-level comment', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $parent = ProposalComment::factory()->create(['proposal_id' => $proposal->id]);

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'A reply',
        'parent_id' => $parent->id,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('proposal_comments', [
        'proposal_id' => $proposal->id,
        'parent_id' => $parent->id,
        'body' => 'A reply',
    ]);
});

test('replying to a reply is rejected (one level of threading)', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $parent = ProposalComment::factory()->create(['proposal_id' => $proposal->id]);
    $reply = ProposalComment::factory()->create([
        'proposal_id' => $proposal->id,
        'parent_id' => $parent->id,
    ]);

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'Too deep',
        'parent_id' => $reply->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
});

test('parent comment must belong to the same proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();
    $foreignParent = ProposalComment::factory()->create();

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'Cross-proposal reply',
        'parent_id' => $foreignParent->id,
    ]);

    $response->assertSessionHasErrors('parent_id');
});

test('deleting a parent removes its replies', function () {
    $user = User::factory()->create();
    $parent = ProposalComment::factory()->create(['user_id' => $user->id]);
    $reply = ProposalComment::factory()->create([
        'proposal_id' => $parent->proposal_id,
        'parent_id' => $parent->id,
    ]);

    $this->actingAs($user)->delete("/comments/{$parent->id}");

    $this->assertDatabaseMissing('proposal_comments', ['id' => $reply->id]);
});
