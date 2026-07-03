<?php

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\User;

test('authenticated users can add a comment', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", [
        'body' => 'This is a test comment',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('proposal_comments', [
        'proposal_id' => $proposal->id,
        'user_id' => $user->id,
        'body' => 'This is a test comment',
    ]);
});

test('comment requires body', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($user)->post("/proposals/{$proposal->id}/comments", []);

    $response->assertSessionHasErrors('body');
});

test('authors can delete their comment', function () {
    $user = User::factory()->create();
    $comment = ProposalComment::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete("/comments/{$comment->id}");

    $response->assertRedirect();
    $this->assertDatabaseMissing('proposal_comments', ['id' => $comment->id]);
});

test('non-authors cannot delete a comment', function () {
    $user = User::factory()->create();
    $comment = ProposalComment::factory()->create();

    $response = $this->actingAs($user)->delete("/comments/{$comment->id}");

    $response->assertForbidden();
    $this->assertDatabaseHas('proposal_comments', ['id' => $comment->id]);
});

test('guests cannot add comments', function () {
    $proposal = Proposal::factory()->create();

    $response = $this->post("/proposals/{$proposal->id}/comments", [
        'body' => 'Test',
    ]);

    $response->assertRedirect(route('login'));
});
