<?php

use App\Models\TrackerRelease;
use App\Support\ProfileHandle;
use Inertia\Testing\AssertableInertia;

/**
 * The tracker as a public page.
 *
 * This is the difference between a tracker and a feature inside an app: a
 * release has an address anybody can open, paste into a message and archive,
 * with no wallet, no account and no client. So what is checked here is that
 * both addresses answer to a stranger, that the filters in the query string
 * are the filters that get applied, and that a hidden release is gone from
 * both.
 */
function pageRelease(array $overrides = []): TrackerRelease
{
    return TrackerRelease::create(array_merge([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Cyberia Sessions',
        'description' => 'six tracks',
        'category' => 'audio',
        'size_bytes' => 2048,
        'file_count' => 2,
        'files' => [['path' => '01.flac', 'length' => 1024], ['path' => '02.flac', 'length' => 1024]],
        'magnet' => 'magnet:?xt=urn:btih:'.str_repeat('a', 40),
        'chain_id' => 49406,
        'contract' => '0x546462fabf30734e63b64f32b30ec8add9b6eba7',
        'token_id' => '4',
        'owner' => '0x00000000000000000000000000000000000000aa',
        'token_uri' => 'ipfs://bafy/release.json',
        'media_kind' => 'audio',
    ], $overrides));
}

test('a stranger can read the index and one release', function () {
    pageRelease();

    $this->get('/tracker')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tracker')
            ->where('results.total', 1)
            ->where('results.releases.0.name', 'Cyberia Sessions')
            // The token is on every row, because the index is a view over the
            // chain and this is how a reader checks it rather than trusting us.
            ->where('results.releases.0.token_id', '4')
            ->where('context.announce_url', config('tracker.announce_url'))
            ->where('release', null));

    $this->get('/tracker/'.str_repeat('a', 40))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tracker')
            ->where('release.info_hash', str_repeat('a', 40))
            ->where('release.file_count', 2)
            ->where('results', null));
});

test('the filters in the address are the filters that are applied', function () {
    pageRelease();
    pageRelease([
        'info_hash' => str_repeat('b', 40),
        'name' => 'A film',
        'category' => 'video',
        'token_id' => '5',
        'size_bytes' => 9_000_000,
    ]);

    $this->get('/tracker?category=video')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.total', 1)
            ->where('results.releases.0.name', 'A film')
            ->where('results.filters.category', 'video'));

    $this->get('/tracker?q=sessions')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1));

    $this->get('/tracker?sort=size')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.releases.0.name', 'A film'));

    // A category nobody defined is ignored rather than answered with nothing:
    // an unknown filter is a typo in a URL, not a request for an empty page.
    $this->get('/tracker?category=nonsense')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 2));
});

test('a hidden release is gone from the page as well as from the tracker', function () {
    $release = pageRelease();
    $release->forceFill(['hidden_at' => now()])->save();

    $this->get('/tracker')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));
    $this->get('/tracker/'.$release->info_hash)->assertNotFound();
    $this->getJson('/api/tracker/releases/'.$release->info_hash)->assertNotFound();
});

test('a profile handle can never shadow the tracker', function () {
    // `/announce` and `/scrape` are registered after the root profile route,
    // so the reservation is what keeps them reachable at all — see
    // App\Support\ProfileHandle::routePattern().
    foreach (['announce', 'scrape', 'tracker'] as $handle) {
        expect(ProfileHandle::isCanonical($handle))->toBeFalse();
    }

    expect(preg_match('/\A'.ProfileHandle::routePattern().'\z/D', 'announce'))->toBe(0)
        ->and(preg_match('/\A'.ProfileHandle::routePattern().'\z/D', 'netrunner'))->toBe(1);
});
