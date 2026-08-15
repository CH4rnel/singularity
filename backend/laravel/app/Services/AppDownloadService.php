<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What /download offers, and where each file actually lives.
 *
 * The installers are built by .github/workflows/apps.yml and published as a
 * GitHub release per `app-v*` tag. Two addresses point at the same file:
 *
 *  - the release asset URL, which the API gives us together with a version, a
 *    date and a byte size;
 *  - /releases/latest/download/<file>, which needs no API call and never
 *    changes, because the artifact names carry no version.
 *
 * So the page prefers the API and degrades to the permanent URL. The one thing
 * it will not do is offer a file the API says the release does not have: an
 * Android button that 404s is worse than no Android button, and until the
 * signing key exists there is genuinely no APK to hand out.
 *
 * Three states, because they read differently to a visitor:
 *
 *  - `published` — a release was found; only its files are offered.
 *  - `none`      — GitHub answered and there is no app release yet; nothing is
 *                  offered, because every link would 404.
 *  - `unknown`   — GitHub could not be read; the permanent URLs are offered
 *                  without a version, since they are right whenever a release
 *                  exists and this is the one case we cannot tell.
 */
class AppDownloadService
{
    // Bumped with the payload shape: a v1 entry has no `reachable` key, and a
    // deploy would spend the rest of the hour reading it as an outage.
    private const CACHE_KEY = 'downloads.release.v2';

    /**
     * The download catalogue, ready for the page and the JSON endpoint.
     *
     * @return array{version: string|null, publishedAt: string|null, releaseUrl: string, repoUrl: string, checksumsUrl: string|null, status: string, builds: list<array{id: string, platform: string, primary: bool, file: string, url: string, size: int|null}>}
     */
    public function catalog(): array
    {
        $lookup = $this->latestRelease();
        $release = $lookup['release'];
        $reachable = $lookup['reachable'];
        $assets = $release['assets'] ?? [];

        $builds = [];

        foreach ((array) config('downloads.builds', []) as $build) {
            $file = (string) $build['file'];
            $asset = $assets[$file] ?? null;

            // A release that does not carry this file did not ship it, and with
            // no release at all there is nothing to link to. Only an unreadable
            // GitHub earns the benefit of the doubt.
            if ($asset === null && $reachable) {
                continue;
            }

            $builds[] = [
                'id' => (string) $build['id'],
                'platform' => (string) $build['platform'],
                'primary' => (bool) ($build['primary'] ?? false),
                'file' => $file,
                'url' => $asset['url'] ?? $this->permanentUrl($file),
                'size' => $asset['size'] ?? null,
            ];
        }

        $checksums = (string) config('downloads.checksums_file');

        return [
            'version' => $release['version'] ?? null,
            'publishedAt' => $release['publishedAt'] ?? null,
            'releaseUrl' => $release['url'] ?? $this->releasesUrl(),
            'repoUrl' => 'https://github.com/'.$this->repo(),
            'checksumsUrl' => $assets[$checksums]['url'] ?? null,
            'status' => match (true) {
                $release !== null => 'published',
                $reachable => 'none',
                default => 'unknown',
            },
            'builds' => $builds,
        ];
    }

    /**
     * The newest published app release, and whether GitHub answered at all.
     *
     * @return array{release: array{version: string, tag: string, url: string, publishedAt: string|null, assets: array<string, array{url: string, size: int}>}|null, reachable: bool}
     */
    private function latestRelease(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && array_key_exists('reachable', $cached)) {
            return $cached;
        }

        $lookup = $this->fetchLatestRelease();

        Cache::put(
            self::CACHE_KEY,
            $lookup,
            // Anything other than a release in hand is re-checked soon: the
            // first release, and a GitHub outage, both end without warning.
            $lookup['release'] === null
                ? (int) config('downloads.failure_cache_ttl', 300)
                : (int) config('downloads.cache_ttl', 3600),
        );

        return $lookup;
    }

    /**
     * @return array{release: array{version: string, tag: string, url: string, publishedAt: string|null, assets: array<string, array{url: string, size: int}>}|null, reachable: bool}
     */
    private function fetchLatestRelease(): array
    {
        $prefix = (string) config('downloads.tag_prefix', 'app-v');
        $token = (string) config('downloads.token', '');

        try {
            $request = Http::timeout(6)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    // GitHub rejects API calls without one.
                    'User-Agent' => 'cyberia.church',
                ]);

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request->get(
                'https://api.github.com/repos/'.$this->repo().'/releases',
                ['per_page' => 30],
            );

            if (! $response->successful()) {
                return ['release' => null, 'reachable' => false];
            }

            $releases = $response->json();
        } catch (Throwable) {
            return ['release' => null, 'reachable' => false];
        }

        if (! is_array($releases)) {
            return ['release' => null, 'reachable' => false];
        }

        // Not /releases/latest: this is a monorepo, and "latest" there is
        // whatever was released last, app or not.
        foreach ($releases as $release) {
            if (! is_array($release)) {
                continue;
            }

            $tag = (string) ($release['tag_name'] ?? '');

            if ($tag === '' || ! str_starts_with($tag, $prefix)) {
                continue;
            }

            if (($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                continue;
            }

            return [
                'release' => [
                    'version' => substr($tag, strlen($prefix)),
                    'tag' => $tag,
                    'url' => (string) ($release['html_url'] ?? $this->releasesUrl()),
                    'publishedAt' => $release['published_at'] ?? null,
                    'assets' => $this->assets($release['assets'] ?? []),
                ],
                'reachable' => true,
            ];
        }

        // GitHub answered; this repository simply has no app release yet.
        return ['release' => null, 'reachable' => true];
    }

    /**
     * @param  mixed  $assets
     * @return array<string, array{url: string, size: int}>
     */
    private function assets($assets): array
    {
        $indexed = [];

        foreach ((array) $assets as $asset) {
            if (! is_array($asset) || ! isset($asset['name'], $asset['browser_download_url'])) {
                continue;
            }

            $indexed[(string) $asset['name']] = [
                'url' => (string) $asset['browser_download_url'],
                'size' => (int) ($asset['size'] ?? 0),
            ];
        }

        return $indexed;
    }

    private function permanentUrl(string $file): string
    {
        return 'https://github.com/'.$this->repo().'/releases/latest/download/'.$file;
    }

    private function releasesUrl(): string
    {
        return 'https://github.com/'.$this->repo().'/releases';
    }

    private function repo(): string
    {
        return trim((string) config('downloads.repo'), '/');
    }
}
