<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

/**
 * A store build of the mobile app stays on the wallet (ConfineStoreEdition).
 *
 * The rules worth pinning: only a page load from a store build is sent back,
 * the wallet's own JSON reads keep working from inside it, and nothing changes
 * for a browser or the sideloaded APK.
 */
const STORE_EDITION_PLAY_UA = 'Mozilla/5.0 (Linux; Android 15) Chrome/141.0 Mobile CyberiaMobile/1.0.0 CyberiaStore/play';
const STORE_EDITION_APK_UA = 'Mozilla/5.0 (Linux; Android 15) Chrome/141.0 Mobile CyberiaMobile/1.0.0';

beforeEach(function () {
    Route::middleware('web')->get('store-probe', fn () => 'site page');
    Route::middleware('web')->get('wallet/store-probe', fn () => 'wallet page');
});

it('sends a store build back to the wallet from any other page', function () {
    $this->withHeader('User-Agent', STORE_EDITION_PLAY_UA)
        ->get('/store-probe')
        ->assertRedirect('/wallet');

    // The current asset version, or Inertia answers a 409 wherever a build exists (CI builds first).
    $this->withHeaders([
        'User-Agent' => STORE_EDITION_PLAY_UA,
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
    ])
        ->get('/store-probe')
        ->assertRedirect('/wallet');
});

it('lets a store build open the wallet itself', function () {
    $this->withHeader('User-Agent', STORE_EDITION_PLAY_UA)
        ->get('/wallet/store-probe')
        ->assertOk()
        ->assertSee('wallet page');
});

it('keeps the wallet\'s JSON reads working inside a store build', function () {
    $this->withHeader('User-Agent', STORE_EDITION_PLAY_UA)
        ->getJson('/store-probe')
        ->assertOk();
});

it('leaves browsers and the sideloaded APK alone', function (string $agent) {
    $this->withHeader('User-Agent', $agent)
        ->get('/store-probe')
        ->assertOk()
        ->assertSee('site page');
})->with([
    'apk' => STORE_EDITION_APK_UA,
    'browser' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0',
    'unknown store' => STORE_EDITION_APK_UA.' CyberiaStore/huawei',
]);
