<?php

namespace App\Http\Controllers;

use App\Services\BridgeConfigService;
use App\Services\CyberiaPrices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /cyber — what the coin is for, with a receipt under every claim.
 *
 * The page exists because the question "how does CYBER become integral the way
 * ETH is" was asked in public and the honest answer has two halves. The first
 * half is a list of things the chain genuinely cannot do without CYBER, each
 * one pointing at the contract that enforces it. The second half is the list of
 * things people assume a chain's coin does — secure consensus, govern the
 * network — which this one does not, because the chain seals with a single
 * validator and no DAO governs it.
 *
 * Printing the second list is the whole reason the first list is believable.
 * Every claim here is checkable in a block explorer in under a minute, and a
 * reader who checks one and finds it false stops believing the other nine.
 *
 * Live figures come from the DEX pool snapshot the bot maintains (`dex_pools`),
 * the same source /tokens and /analytics price from, so the three pages cannot
 * disagree. That table is not created by a migration here, so every figure is
 * nullable and renders as "—" rather than as a zero — an unmeasured reserve and
 * an empty one read identically otherwise, and only one of them is bad news.
 */
class CyberController extends Controller
{
    public function __construct(
        private readonly CyberiaPrices $prices,
        private readonly BridgeConfigService $bridge,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('Cyber', [
            'chain' => (array) config('cyber.chain'),
            'contracts' => (array) config('cyber.contracts'),
            'launchpad' => (array) config('cyber.launchpad'),
            'corridors' => $this->corridors(),
            'market' => Cache::remember(
                'cyber.page.market',
                (int) config('cyber.cache_ttl', 300),
                fn (): array => $this->market(),
            ),
        ]);
    }

    /**
     * The bridge corridors that actually carry the coin, with the state they
     * are in right now.
     *
     * Read live rather than written into the page because a corridor's state is
     * an environment variable on the host: the outbound Robinhood lane opens
     * the day the relayer is funded there, and a page claiming the coin already
     * travels both ways would be wrong until then and stale after it.
     *
     * @return list<array{token: string, from: string, to: string, open: bool, note: string|null}>
     */
    private function corridors(): array
    {
        $wanted = ['CYBER', 'CYBER.sol'];

        return collect($this->bridge->publicRoutes())
            ->flatMap(fn (array $route): array => collect($route['tokens'])
                ->intersect($wanted)
                ->map(fn (string $token): array => [
                    'token' => $token,
                    'from' => $route['sourceLabel'],
                    'to' => $route['destinationLabel'],
                    'open' => (bool) $route['operational'],
                    'note' => $route['unavailableReason'],
                ])
                ->all())
            ->values()
            ->all();
    }

    /**
     * What the pool graph can say about the coin right now.
     *
     * @return array{price: float|null, pools: int|null, locked: float|null, locked_usd: float|null}
     */
    private function market(): array
    {
        $pools = $this->pools();

        if ($pools->isEmpty()) {
            return ['price' => null, 'pools' => null, 'locked' => null, 'locked_usd' => null];
        }

        $wcyber = strtolower((string) config('cyber.contracts.wcyber'));
        $price = $this->prices->priceFromPools($pools)[$wcyber] ?? null;

        // Every pool quoted against the coin, and how much of the coin is
        // sitting in them. Reserves in this table are already scaled by the
        // token's decimals, so the two sides are directly comparable.
        $quoted = $pools->filter(fn (object $pool): bool => in_array(
            $wcyber,
            [strtolower((string) $pool->token0), strtolower((string) $pool->token1)],
            true,
        ));

        $locked = $quoted->sum(fn (object $pool): float => strtolower((string) $pool->token0) === $wcyber
            ? (float) $pool->reserve0
            : (float) $pool->reserve1);

        return [
            'price' => $price !== null ? (float) $price : null,
            'pools' => $quoted->count(),
            'locked' => $locked,
            'locked_usd' => $price !== null ? $locked * (float) $price : null,
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function pools(): Collection
    {
        return Schema::hasTable('dex_pools')
            ? DB::table('dex_pools')->get()
            : collect();
    }
}
