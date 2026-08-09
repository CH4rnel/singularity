<?php

use App\Services\AppDownloadService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * /download reads the GitHub release the app workflow publishes.
 *
 * The rules worth pinning: a file the release does not carry is not offered at
 * all, and an unreachable GitHub still leaves every download working through
 * its permanent /releases/latest/download address.
 */
beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

const RELEASES_API = 'https://api.github.com/repos/*';

function releaseAsset(string $name, int $size = 1048576): array
{
    return [
        'name' => $name,
        'size' => $size,
        'browser_download_url' => "https://github.com/cyberia-temple/singularity/releases/download/app-v1.2.3/{$name}",
    ];
}

function fakeRelease(array $assets, string $tag = 'app-v1.2.3'): void
{
    Http::fake([
        RELEASES_API => Http::response([[
            'tag_name' => $tag,
            'draft' => false,
            'prerelease' => false,
            'html_url' => "https://github.com/cyberia-temple/singularity/releases/tag/{$tag}",
            'published_at' => '2026-08-09T10:00:00Z',
            'assets' => $assets,
        ]]),
    ]);
}

it('offers every file the published release carries', function () {
    fakeRelease([
        releaseAsset('Cyberia-Setup-x64.exe', 90_000_000),
        releaseAsset('Cyberia-portable-x64.exe'),
        releaseAsset('Cyberia-mac-arm64.dmg'),
        releaseAsset('Cyberia-mac-x64.dmg'),
        releaseAsset('Cyberia-linux-x86_64.AppImage'),
        releaseAsset('Cyberia-linux-amd64.deb'),
        releaseAsset('Cyberia.apk'),
        releaseAsset('SHA256SUMS.txt'),
    ]);

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Download')
            ->where('catalog.version', '1.2.3')
            ->where('catalog.status', 'published')
            ->has('catalog.builds', 7)
            ->where('catalog.builds.0.id', 'windows-installer')
            ->where('catalog.builds.0.size', 90_000_000)
            ->where('catalog.builds.0.url', 'https://github.com/cyberia-temple/singularity/releases/download/app-v1.2.3/Cyberia-Setup-x64.exe')
            ->where('catalog.checksumsUrl', 'https://github.com/cyberia-temple/singularity/releases/download/app-v1.2.3/SHA256SUMS.txt'));
});

it('drops a platform the release has no file for', function () {
    // What an unsigned Android job leaves behind: desktop installers, no APK.
    fakeRelease([
        releaseAsset('Cyberia-Setup-x64.exe'),
        releaseAsset('Cyberia-linux-x86_64.AppImage'),
    ]);

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('catalog.builds', 2)
            ->where('catalog.builds.0.id', 'windows-installer')
            ->where('catalog.builds.1.id', 'linux-appimage')
            ->where('catalog.checksumsUrl', null));
});

it('ignores releases that are not app builds', function () {
    Http::fake([
        RELEASES_API => Http::response([
            [
                'tag_name' => 'v9.9.9',
                'draft' => false,
                'prerelease' => false,
                'assets' => [releaseAsset('something-else.zip')],
            ],
            [
                'tag_name' => 'app-v1.0.0',
                'draft' => false,
                'prerelease' => false,
                'html_url' => 'https://github.com/cyberia-temple/singularity/releases/tag/app-v1.0.0',
                'published_at' => '2026-08-01T10:00:00Z',
                'assets' => [releaseAsset('Cyberia.apk')],
            ],
        ]),
    ]);

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.version', '1.0.0')
            ->has('catalog.builds', 1)
            ->where('catalog.builds.0.id', 'android-apk'));
});

it('keeps every download working when GitHub cannot be reached', function () {
    Http::fake([RELEASES_API => Http::response(status: 503)]);

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.version', null)
            ->where('catalog.status', 'unknown')
            // Nothing is hidden on a failed lookup: these URLs are permanent.
            ->has('catalog.builds', 7)
            ->where('catalog.builds.0.url', 'https://github.com/cyberia-temple/singularity/releases/latest/download/Cyberia-Setup-x64.exe')
            ->where('catalog.builds.0.size', null));
});

it('offers nothing at all before the first release is published', function () {
    // GitHub answers, the repository simply has no app release: every download
    // would 404, so the page says so instead of drawing seven dead buttons.
    Http::fake([RELEASES_API => Http::response([])]);

    $this->get(route('download'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('catalog.status', 'none')
            ->where('catalog.version', null)
            ->has('catalog.builds', 0));
});

it('redirects the per-platform short link to that platform s file', function () {
    fakeRelease([releaseAsset('Cyberia.apk')]);

    $this->get('/download/android')
        ->assertRedirect('https://github.com/cyberia-temple/singularity/releases/download/app-v1.2.3/Cyberia.apk');
});

it('sends the short link back to the page when that platform has no build', function () {
    fakeRelease([releaseAsset('Cyberia-Setup-x64.exe')]);

    $this->get('/download/android')->assertRedirect(route('download'));
});

it('serves the same catalogue as JSON', function () {
    fakeRelease([releaseAsset('Cyberia.apk')]);

    $this->getJson('/api/downloads')
        ->assertOk()
        ->assertJsonPath('version', '1.2.3')
        ->assertJsonPath('builds.0.file', 'Cyberia.apk');
});

it('reads GitHub once per hour, not once per visitor', function () {
    fakeRelease([releaseAsset('Cyberia.apk')]);

    $downloads = app(AppDownloadService::class);
    $downloads->catalog();
    $downloads->catalog();

    Http::assertSentCount(1);
});

it('does not hammer GitHub while it is down', function () {
    Http::fake([RELEASES_API => Http::response(status: 503)]);

    $downloads = app(AppDownloadService::class);
    $downloads->catalog();
    $downloads->catalog();

    Http::assertSentCount(1);
});
