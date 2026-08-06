<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot follow or unfollow users', function () {
    $user = User::factory()->create();

    $this->post(route('users.follow.store', $user))
        ->assertRedirect(route('login'));
    $this->delete(route('users.follow.destroy', $user))
        ->assertRedirect(route('login'));
});

test('users can follow and unfollow another user', function () {
    $follower = User::factory()->create();
    $followed = User::factory()->create();

    $this->actingAs($follower)
        ->post(route('users.follow.store', $followed))
        ->assertRedirect()
        ->assertSessionHas('status', 'user-followed');

    expect($follower->following()->whereKey($followed->id)->exists())->toBeTrue()
        ->and($followed->followers()->whereKey($follower->id)->exists())->toBeTrue();

    $this->actingAs($follower)
        ->get(route('users.legacy', $followed))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.is_following', true));

    $this->actingAs($follower)
        ->delete(route('users.follow.destroy', $followed))
        ->assertRedirect()
        ->assertSessionHas('status', 'user-unfollowed');

    $this->assertDatabaseMissing('user_follows', [
        'follower_id' => $follower->id,
        'followed_id' => $followed->id,
    ]);
});

test('following is idempotent', function () {
    $follower = User::factory()->create();
    $followed = User::factory()->create();

    $this->actingAs($follower)->post(route('users.follow.store', $followed));
    $this->actingAs($follower)->post(route('users.follow.store', $followed));

    $this->assertDatabaseCount('user_follows', 1);
});

test('users cannot follow themselves', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('users.follow.store', $user))
        ->assertUnprocessable();

    $this->assertDatabaseCount('user_follows', 0);
});
