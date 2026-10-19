<?php

namespace App\Services\Launchpad;

use App\Models\LaunchpadToken;
use App\Services\IpfsService;
use App\Support\Handles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The underground: every launchpad token's homepage, in one directory.
 *
 * A token already gets a page of its own — one HTML file on its own subdomain,
 * pinned to IPFS (LaunchpadController). What it did not have was a
 * neighbourhood: nowhere listed them, nothing linked one to the next, and
 * nobody could tell a page that a hundred people had opened from one nobody
 * had. This is that neighbourhood, built the way the old web built them — a
 * directory, a web ring, a hit counter — and every token without a page is a
 * vacant lot with a cover of its own, waiting for its creator to move in.
 *
 * The ring has one stop that is not on the map. Past the last homepage, and
 * once in a while on a random ride, it goes to `/lost`.
 */
class Underground
{
    /** One random ride in this many ends up somewhere that is not a homepage. */
    private const LOST_ODDS = 13;

    public function __construct(private readonly IpfsService $ipfs) {}

    public function url(string $path = ''): string
    {
        return config('launchpad.underground_url').($path === '' ? '' : '/'.ltrim($path, '/'));
    }

    /**
     * A homepage's own address. Built off the underground's URL rather than a
     * hard-coded `https://`, so a local run on `*.localhost:8000` links to
     * itself instead of to production.
     */
    public function siteUrl(string $subdomain): string
    {
        $base = parse_url($this->url()) ?: [];
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return ($base['scheme'] ?? 'https').'://'.$subdomain.'.'.config('launchpad.sites_domain').$port.'/';
    }

    /**
     * Homepages, freshest first — "last updated" is what a directory of
     * homepages was always sorted by.
     *
     * @return Collection<int, LaunchpadToken>
     */
    public function homes(): Collection
    {
        return LaunchpadToken::query()
            ->with('dao:id,name')
            ->whereNotNull('html_path')
            ->get()
            ->sortByDesc(fn (LaunchpadToken $token) => ($token->site_updated_at ?? $token->updated_at)?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * Tokens with no homepage yet, newest launch first.
     *
     * @return Collection<int, LaunchpadToken>
     */
    public function lots(): Collection
    {
        return LaunchpadToken::query()
            ->with('dao:id,name')
            ->whereNull('html_path')
            ->get()
            ->sortByDesc(fn (LaunchpadToken $token) => ($token->launched_at ?? $token->created_at)?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * The ring: every homepage with a subdomain, in the order they moved in.
     *
     * @return array<int, string>
     */
    public function ring(): array
    {
        return LaunchpadToken::query()
            ->whereNotNull('html_path')
            ->whereNotNull('site_subdomain')
            ->orderBy('id')
            ->pluck('site_subdomain')
            ->all();
    }

    /**
     * Where one step along the ring leads from `$from`.
     *
     * `lost` sits between the last stop and the first in both directions, so
     * anybody who rides the ring all the way round passes through it once.
     * Arriving from somewhere the ring does not know starts at either end.
     */
    public function step(string $direction, ?string $from): string
    {
        $ring = $this->ring();

        if ($ring === []) {
            return $this->url();
        }

        $index = $from === null ? false : array_search($from, $ring, true);
        $last = count($ring) - 1;

        if ($direction === 'random') {
            $others = array_values(array_filter($ring, fn (string $stop) => $stop !== $from));

            if ($others === [] || random_int(1, self::LOST_ODDS) === 1) {
                return $this->url('lost');
            }

            return $this->siteUrl($others[array_rand($others)]);
        }

        if ($from === 'lost') {
            return $this->siteUrl($direction === 'next' ? $ring[0] : $ring[$last]);
        }

        if ($index === false) {
            return $this->siteUrl($direction === 'next' ? $ring[0] : $ring[$last]);
        }

        $target = $direction === 'next' ? $index + 1 : $index - 1;

        return $target < 0 || $target > $last
            ? $this->url('lost')
            : $this->siteUrl($ring[$target]);
    }

    /**
     * Count a visit: one per address per site per UTC day. The address is
     * hashed with the app key before it becomes a cache key, so the store never
     * holds a list of who looked at what.
     */
    public function recordHit(LaunchpadToken $token, ?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }

        $day = now('UTC');
        $key = 'underground:hit:'.$token->getKey().':'
            .hash('sha256', config('app.key').'|'.$ip.'|'.$day->toDateString());

        if (! Cache::add($key, true, $day->copy()->endOfDay())) {
            return;
        }

        try {
            // Through the base query: a hit is not an edit and must not move
            // `updated_at`, which the directory reads as "last changed".
            LaunchpadToken::query()->whereKey($token->getKey())->toBase()->increment('site_hits');
        } catch (\Throwable $exception) {
            // Not counted, so not remembered as counted either: the next
            // visit today gets another chance.
            Cache::forget($key);

            throw $exception;
        }
    }

    /**
     * One token as the directory, the wallet and the page all describe it.
     *
     * @return array<string, mixed>
     */
    public function entry(LaunchpadToken $token): array
    {
        $address = Str::lower($token->address);
        $subdomain = $token->site_subdomain;

        return [
            'chain_id' => (int) $token->chain_id,
            'address' => $address,
            'symbol' => $token->symbol,
            'name' => $token->name,
            'description' => $token->description,
            'creator' => $token->creator ? Str::lower($token->creator) : null,
            'image_url' => $token->image_path ? Storage::disk('public')->url($token->image_path) : null,
            'site_subdomain' => $subdomain,
            'site_url' => $this->homepageUrl($token),
            'site_hits' => (int) $token->site_hits,
            'site_updated_at' => ($token->site_updated_at ?? ($token->html_path ? $token->updated_at : null))?->toIso8601String(),
            'cover_url' => $this->url('cover/'.$address.'.svg'),
            'counter_url' => $subdomain ? $this->url('counter/'.$subdomain.'.svg') : null,
            'buy_url' => config('launchpad.swap_url').'?outputCurrency='.$address,
            'dao_url' => $token->dao ? route('dao.show', $token->dao) : null,
            'x_url' => Handles::xUrl($token->x_handle),
            'telegram_url' => Handles::telegramUrl($token->telegram_handle),
            'website_url' => $token->website_url,
            'launched_at' => $token->launched_at?->toIso8601String(),
        ];
    }

    /**
     * Everything at once, for the wallet's layer 08 and the page.
     *
     * @return array{underground_url: string, ring: array<int, string>, homes: array<int, array<string, mixed>>, lots: array<int, array<string, mixed>>}
     */
    public function directory(): array
    {
        return [
            'underground_url' => $this->url(),
            'ring' => $this->ring(),
            'homes' => $this->homes()->map(fn (LaunchpadToken $token) => $this->entry($token))->all(),
            'lots' => $this->lots()->map(fn (LaunchpadToken $token) => $this->entry($token))->all(),
        ];
    }

    private function homepageUrl(LaunchpadToken $token): ?string
    {
        if (! $token->html_path) {
            return null;
        }

        if ($token->site_subdomain) {
            return $this->siteUrl($token->site_subdomain);
        }

        return $token->ipfs_cid
            ? $this->ipfs->gatewayUrl($token->ipfs_cid)
            : url('/launchpad/sites/'.Str::lower($token->address));
    }
}
