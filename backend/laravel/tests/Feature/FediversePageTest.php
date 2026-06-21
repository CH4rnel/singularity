<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot reach the fediverse tool', function () {
    $this->get(route('fediverse'))->assertRedirect(route('login'));
});

test('authenticated users see an empty lookup tool', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('fediverse'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Fediverse')
            ->where('actor', null)
            ->where('error', null)
        );
});

test('a malformed handle is rejected without any network call', function () {
    $user = User::factory()->create();

    // No '@' and not a URL, so the service throws before touching the network.
    $this->actingAs($user)
        ->get(route('fediverse', ['handle' => 'notahandle']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Fediverse')
            ->where('handle', 'notahandle')
            ->where('actor', null)
            ->whereNot('error', null)
        );
});
