<?php

use App\Models\Activity;
use App\Models\Proposal;
use App\Models\User;

test('anyone can view a user profile', function () {
    $user = User::factory()->create();

    $response = $this->get("/u/{$user->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id)
            ->has('stats')
            ->has('activities'));
});

test('profile shows the user activity and stats', function () {
    $user = User::factory()->create();
    $proposal = Proposal::factory()->create(['user_id' => $user->id]);
    Activity::factory()->create([
        'user_id' => $user->id,
        'subject_type' => Proposal::class,
        'subject_id' => $proposal->id,
    ]);

    $response = $this->get("/u/{$user->id}");

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.proposals', 1)
            ->has('activities.data', 1));
});

test('missing users return 404', function () {
    $response = $this->get('/u/999999');

    $response->assertNotFound();
});
