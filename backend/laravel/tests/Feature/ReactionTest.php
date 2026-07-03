<?php

use App\Models\Proposal;
use App\Models\ProposalComment;
use App\Models\Reaction;
use App\Models\User;

test('guests cannot react', function () {
    $proposal = Proposal::factory()->create();

    $response = $this->post('/reactions', [
        'reactable_type' => 'proposal',
        'reactable_id' => $proposal->id,
        'emoji' => '🔥',
    ]);

    $response->assertRedirect(route('login'));
});

test('users can react to a proposal', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($user)->post('/reactions', [
        'reactable_type' => 'proposal',
        'reactable_id' => $proposal->id,
        'emoji' => '🔥',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('reactions', [
        'user_id' => $user->id,
        'reactable_type' => Proposal::class,
        'reactable_id' => $proposal->id,
        'emoji' => '🔥',
    ]);
});

test('reacting twice with the same emoji toggles it off', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $payload = [
        'reactable_type' => 'proposal',
        'reactable_id' => $proposal->id,
        'emoji' => '👍',
    ];

    $this->actingAs($user)->post('/reactions', $payload);
    $this->actingAs($user)->post('/reactions', $payload);

    expect(Reaction::count())->toBe(0);
});

test('users can react to a comment', function () {
    $user = User::factory()->create();
    $comment = ProposalComment::factory()->create();

    $this->actingAs($user)->post('/reactions', [
        'reactable_type' => 'comment',
        'reactable_id' => $comment->id,
        'emoji' => '🚀',
    ]);

    $this->assertDatabaseHas('reactions', [
        'reactable_type' => ProposalComment::class,
        'reactable_id' => $comment->id,
    ]);
});

test('emoji outside the palette is rejected', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create();

    $response = $this->actingAs($user)->post('/reactions', [
        'reactable_type' => 'proposal',
        'reactable_id' => $proposal->id,
        'emoji' => '💩',
    ]);

    $response->assertSessionHasErrors('emoji');
});

test('arbitrary reactable types are rejected', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/reactions', [
        'reactable_type' => 'user',
        'reactable_id' => $user->id,
        'emoji' => '👍',
    ]);

    $response->assertSessionHasErrors('reactable_type');
});
