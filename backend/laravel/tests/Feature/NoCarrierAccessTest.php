<?php

use App\Models\User;
use App\Services\GamificationService;

/**
 * The game, behind an unlock.
 *
 * The whole export is served through the app rather than from `public/`, so
 * the gate covers every file and not just the front door — an asset path
 * anybody could guess would make the price a formality. That is what most of
 * this pins.
 */
function withBuild(): string
{
    $dir = storage_path('framework/testing/nocarrier');

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($dir.'/index.html', '<html><head><title>NO CARRIER</title></head><body><canvas id="canvas"></canvas></body></html>');
    file_put_contents($dir.'/index.wasm', 'wasm');
    file_put_contents($dir.'/index.pck', 'pack');

    config()->set('gamification.nocarrier_path', $dir);

    return $dir;
}

function owner(): User
{
    $user = User::factory()->create();
    app(GamificationService::class)->award($user, 'swap', 'x', 50_000);
    app(GamificationService::class)->enchant($user, 'nocarrier');

    return $user;
}

beforeEach(fn () => $this->withoutVite());

it('shows the price to somebody who has not unlocked it', function () {
    withBuild();

    $this->actingAs(User::factory()->create())->get('/game/nocarrier')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('game/NoCarrierLocked')->where('cost', 5000));
});

it('serves the game to somebody who has', function () {
    withBuild();

    $this->actingAs(owner())->get('/game/nocarrier')
        ->assertOk()
        ->assertSee('canvas', false);
});

it('will not hand an asset to somebody who has not paid', function () {
    withBuild();

    // The gate is the point: a wasm URL anybody could guess would make the
    // unlock decorative.
    $this->actingAs(User::factory()->create())->get('/game/nocarrier/index.wasm')
        ->assertNotFound();
});

it('serves an asset to somebody who has', function () {
    withBuild();

    $this->actingAs(owner())->get('/game/nocarrier/index.wasm')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/wasm');
});

it('refuses a file type a godot export never asks for', function () {
    withBuild();
    file_put_contents(config('gamification.nocarrier_path').'/.env', 'SECRET=1');

    $this->actingAs(owner())->get('/game/nocarrier/.env')->assertNotFound();
});

it('cannot be talked out of its own directory', function () {
    withBuild();

    // The route pattern refuses the characters, and the controller matches on
    // a basename rather than joining a path — two answers to the same question.
    $this->actingAs(owner())->get('/game/nocarrier/..%2F..%2F.env')->assertNotFound();
});

it('says the build is missing rather than drawing a broken canvas', function () {
    config()->set('gamification.nocarrier_path', storage_path('framework/testing/nothing-here'));

    $this->actingAs(owner())->get('/game/nocarrier')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('game/NoCarrierMissing'));
});

it('states its own directory, because godot asks for siblings by relative name', function () {
    withBuild();

    // Laravel normalises the trailing slash away, so `/game/nocarrier` and
    // `/game/nocarrier/` are one route and a relative `index.js` would resolve
    // to `/game/index.js`. The <base> settles it either way.
    $this->actingAs(owner())->get('/game/nocarrier')
        ->assertOk()
        ->assertSee('<base href="'.route('nocarrier').'/">', false);
});

it('still states its directory when the shell has no head to put it in', function () {
    $dir = withBuild();
    file_put_contents($dir.'/index.html', '<canvas id="canvas"></canvas>');

    // A silent miss here is a game whose every asset 404s.
    $this->actingAs(owner())->get('/game/nocarrier')->assertSee('<base href=', false);
});

it('is closed to a guest', function () {
    $this->get('/game/nocarrier')->assertRedirect('/login');
});
