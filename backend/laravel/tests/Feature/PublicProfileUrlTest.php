<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $this->withoutVite();
});

it('serves a public profile at its on-chain nickname', function () {
    $user = User::factory()->create([
        'name' => 'Local name',
        'onchain_nickname' => 'cyberia_priest',
    ]);

    $this->get('/cyberia_priest')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id)
            ->where('profile.name', 'cyberia_priest'));

    expect($user->profile_url)->toBe('/cyberia_priest');
});

it('permanently redirects a legacy id URL and preserves its query string', function () {
    $user = User::factory()->create([
        'onchain_nickname' => 'cyberia_priest',
    ]);

    $this->get(route('users.legacy', [
        'user' => $user,
        'posts' => 2,
    ]))
        ->assertStatus(Response::HTTP_MOVED_PERMANENTLY)
        ->assertRedirect(route('users.show', [
            'user' => 'cyberia_priest',
            'posts' => 2,
        ]));
});

it('keeps the legacy URL when no canonical nickname exists', function () {
    $user = User::factory()->create(['onchain_nickname' => null]);

    $this->get(route('users.legacy', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id));

    expect($user->profile_url)->toBe("/u/{$user->id}");
});

it('does not let a nickname shadow an application route', function () {
    $user = User::factory()->create(['onchain_nickname' => 'feed']);

    expect($user->profile_url)->toBe("/u/{$user->id}");

    $this->get(route('users.legacy', $user))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('users/Show')
            ->where('profile.id', $user->id));

    $this->get('/feed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Feed'));
});

it('returns not found for unknown and numeric canonical handles', function () {
    $this->get('/missing_handle')->assertNotFound();
    $this->get('/999999')->assertNotFound();
});

it('keeps cached on-chain nicknames unique', function () {
    User::factory()->create(['onchain_nickname' => 'unique_handle']);

    expect(fn () => User::factory()->create([
        'onchain_nickname' => 'unique_handle',
    ]))->toThrow(QueryException::class);
});
