<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A limit written on a route is a limit on that route.
 *
 * Laravel keys `throttle:N,1` by the caller alone, so every throttled endpoint
 * in an application counts into one tally and the strictest number anybody
 * touches becomes the number for everything they do. Here that meant the
 * wallet's own polling — the mailbox every seven seconds, prices every minute,
 * the feed on every tab change — spending the ten-a-minute allowance that
 * belongs to writing a post, and the first press answering "Too Many
 * Attempts".
 *
 * See App\Http\Middleware\ThrottlePerRoute.
 */
beforeEach(function () {
    RateLimiter::clear('');
    cache()->flush();
});

it('does not spend one endpoint\'s allowance on another', function () {
    $user = User::factory()->create();

    // Well past the ten a minute that posting is allowed, on a route that
    // allows sixty — exactly what a few seconds of an open wallet looks like.
    foreach (range(1, 20) as $ignored) {
        $this->actingAs($user)->getJson('/api/wallet/feed')->assertOk();
    }

    $this->actingAs($user)
        ->postJson('/api/wallet/feed', ['body' => 'Hello from the wallet.'])
        ->assertCreated();
});

it('still refuses once an endpoint has had its own limit', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)
            ->postJson('/api/wallet/feed', ['body' => 'Hello from the wallet.'])
            ->assertCreated();
    }

    $this->actingAs($user)
        ->postJson('/api/wallet/feed', ['body' => 'One too many.'])
        ->assertStatus(429);
});

it('counts two people separately on one endpoint', function () {
    $one = User::factory()->create();
    $two = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($one)
            ->postJson('/api/wallet/feed', ['body' => 'Mine.'])
            ->assertCreated();
    }

    $this->actingAs($two)
        ->postJson('/api/wallet/feed', ['body' => 'Theirs.'])
        ->assertCreated();
});
