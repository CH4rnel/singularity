<?php

use App\Http\Controllers\TrackerAnnounceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The BitTorrent tracker
|--------------------------------------------------------------------------
|
| These two are not part of the site. They are spoken to by torrent clients
| that have never heard of Inertia, a session or a CSRF token, several times an
| hour, for as long as a swarm lives — so they are registered outside the `web`
| group on purpose. A session cookie per announce would be a session file per
| announce, written for a client that discards it before the next request.
|
| The paths are the conventional ones because they are baked into every torrent
| this tracker ever produces: `announce_url` in config/tracker.php is what a
| stranger's client will still be calling long after this deploy.
|
*/

Route::get('/announce', [TrackerAnnounceController::class, 'announce'])
    ->middleware('throttle:600,1')
    ->name('tracker.announce');

Route::get('/scrape', [TrackerAnnounceController::class, 'scrape'])
    ->middleware('throttle:120,1')
    ->name('tracker.scrape');
