<?php

namespace App\Http\Controllers;

use App\Services\CyberiaPrices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, human-readable token pages. The analytics dashboard links here from
 * its token list so a symbol resolves to a real page — what the token is and
 * why it exists on Cyberia — instead of a dead end. Descriptions come from
 * config/tokens.php; live USD prices and the pools a token trades in are
 * derived from the DEX pool graph (shared with AnalyticsController via
 * CyberiaPrices), so both pages agree.
 */
class TokenController extends Controller
{
    public function __construct(private readonly CyberiaPrices $prices) {}

    /**
     * Directory of every documented token, grouped by category.
     */
    public function index(): Response
    {
        $pools = $this->pools();
        $priceMap = $this->prices->priceFromPools($pools);

        $registry = (array) config('tokens.list', []);
        $categories = (array) config('tokens.categories', []);

        $groups = collect($categories)
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'tokens' => collect($registry)
                    ->filter(fn (array $meta): bool => ($meta['category'] ?? null) === $key)
                    ->map(fn (array $meta, string $addr): array => $this->present($addr, $meta, $priceMap[$addr] ?? null))
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['tokens'] !== [])
            ->values()
            ->all();

        return Inertia::render('Tokens', [
            'groups' => $groups,
            'count' => count($registry),
        ]);
    }

    /**
     * A single token page, resolved from either a 0x address or a symbol.
     *
     * A well-formed address never 404s: unknown-but-valid addresses (e.g. a
     * freshly listed token the registry hasn't documented yet) still render,
     * carrying whatever symbol/price the pool graph knows, so the analytics
     * links can never land on a dead page.
     */
    public function show(string $token): Response
    {
        $pools = $this->pools();
        $priceMap = $this->prices->priceFromPools($pools);
        $symbols = $this->prices->poolSymbols($pools);
        $registry = (array) config('tokens.list', []);

        $isAddress = (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $token);

        if ($isAddress) {
            $addr = strtolower($token);
            $meta = $registry[$addr] ?? [
                // Undocumented but tradeable: fall back to the on-chain symbol.
                'symbol' => $symbols[$addr] ?? null,
            ];
        } else {
            // Symbol lookup (case-insensitive). Only known symbols resolve.
            $wanted = strtoupper($token);
            $addr = collect($registry)
                ->search(fn (array $meta): bool => strtoupper((string) ($meta['symbol'] ?? '')) === $wanted);

            abort_if($addr === false, 404);
            $meta = $registry[$addr];
        }

        return Inertia::render('Token', [
            'token' => $this->present($addr, $meta, $priceMap[$addr] ?? null),
            'pools' => $this->poolsFor($addr, $pools, $priceMap),
            'explorerUrl' => $this->explorerUrl(),
        ]);
    }

    /**
     * Shape a registry entry (or bare fallback) for the frontend, resolving the
     * logo URL, category label and call-to-action links.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function present(string $addr, array $meta, ?float $price): array
    {
        $symbol = $meta['symbol'] ?? null;
        $known = isset($meta['what']);
        $logoBase = rtrim((string) config('tokens.logo_base'), '/');

        return [
            'address' => $addr,
            'symbol' => $symbol,
            'name' => $meta['name'] ?? null,
            'decimals' => $meta['decimals'] ?? null,
            'logo' => isset($meta['logo']) ? $logoBase.$meta['logo'] : null,
            'categoryKey' => $meta['category'] ?? null,
            'category' => isset($meta['category'])
                ? (config('tokens.categories.'.$meta['category']) ?? null)
                : null,
            'tagline' => $meta['tagline'] ?? null,
            'what' => $meta['what'] ?? null,
            'why' => $meta['why'] ?? null,
            'isKnown' => $known,
            'price' => $price,
            'links' => $this->links($addr, $meta),
        ];
    }

    /**
     * Call-to-action links: any custom ones from config first, then the
     * always-present Trade (on the Ritual DEX) and Explorer links.
     *
     * @param  array<string, mixed>  $meta
     * @return list<array{label: string, url: string, external: bool}>
     */
    private function links(string $addr, array $meta): array
    {
        $links = collect($meta['links'] ?? [])
            ->map(fn (array $link): array => [
                'label' => $link['label'],
                'url' => $link['url'],
                'external' => str_starts_with($link['url'], 'http'),
            ]);

        $swap = rtrim((string) config('tokens.swap_url'), '/');
        $links->push([
            'label' => 'Trade on Ritual',
            'url' => "{$swap}/swap?currency1={$addr}",
            'external' => true,
        ]);
        $links->push([
            'label' => 'View on explorer',
            'url' => "{$this->explorerUrl()}/token/{$addr}",
            'external' => true,
        ]);

        return $links->values()->all();
    }

    /**
     * The pools this token trades in, richest first, each with the paired token.
     *
     * @param  Collection<int, object>  $pools
     * @param  array<string, float>  $priceMap
     * @return list<array<string, mixed>>
     */
    private function poolsFor(string $addr, Collection $pools, array $priceMap): array
    {
        return $pools
            ->filter(fn (object $p): bool => strtolower((string) $p->token0) === $addr
                || strtolower((string) $p->token1) === $addr)
            ->map(function (object $p) use ($addr, $priceMap): array {
                $isToken0 = strtolower((string) $p->token0) === $addr;
                $otherAddr = strtolower((string) ($isToken0 ? $p->token1 : $p->token0));

                return [
                    'pair_address' => $p->pair_address,
                    'symbol0' => $p->symbol0,
                    'symbol1' => $p->symbol1,
                    'other_symbol' => $isToken0 ? $p->symbol1 : $p->symbol0,
                    'other_address' => $otherAddr,
                    'other_known' => isset(config('tokens.list')[$otherAddr]),
                    'tvl_usd' => $this->prices->poolTvl($p, $priceMap),
                ];
            })
            ->sortByDesc('tvl_usd')
            ->values()
            ->all();
    }

    /**
     * All DEX pools, or an empty collection when the bot hasn't indexed any yet.
     *
     * @return Collection<int, object>
     */
    private function pools(): Collection
    {
        return Schema::hasTable('dex_pools')
            ? DB::table('dex_pools')->get()
            : collect();
    }

    private function explorerUrl(): string
    {
        return rtrim((string) config('services.cyberia.explorer_url'), '/');
    }
}
