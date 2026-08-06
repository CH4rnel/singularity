<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * USD prices for the assets the unified wallet holds.
 *
 * The wallet page shows one portfolio total across three chains, so it needs a
 * price per chain and not per DEX pool. CYBER reuses the DexScreener feed the
 * bridge already trusts; SOL and XMR come from CoinGecko, which is the only
 * public source here that covers Monero at all.
 *
 * A missing price is returned as null rather than zero: the UI renders "—" and
 * marks the total partial, because a silent zero would understate a balance the
 * user is about to spend from.
 */
class WalletPriceService
{
    /** Long enough that a page refresh is free, short enough to stay usable. */
    private const TTL_SECONDS = 300;

    private const CACHE_KEY = 'wallet.prices.v1';

    private const COINGECKO_URL = 'https://api.coingecko.com/api/v3/simple/price';

    /**
     * Wallet chain id => CoinGecko id for that chain's native coin. CYBER is
     * not listed there and comes from the DEX feed instead. Several chains
     * share a coin — Robinhood Chain and Base both pay gas in ETH — so the
     * request is deduplicated before it goes out.
     */
    private const COINGECKO_IDS = [
        'robinhood' => 'ethereum',
        'bnb' => 'binancecoin',
        'base' => 'ethereum',
        'solana' => 'solana',
        'monero' => 'monero',
    ];

    public function __construct(private CyberPriceService $cyberPrice) {}

    /**
     * USD price per wallet chain plus the moment the quote was taken.
     *
     * @return array{prices: array<string, float|null>, fetchedAt: string}
     */
    public function quotes(): array
    {
        /** @var array{prices: array<string, float|null>, fetchedAt: string} */
        return Cache::remember(
            self::CACHE_KEY,
            self::TTL_SECONDS,
            fn (): array => [
                'prices' => [
                    'cyberia' => $this->cyberiaUsd(),
                    ...$this->coingeckoUsd(),
                ],
                'fetchedAt' => now()->toIso8601String(),
            ],
        );
    }

    /**
     * Native CYBER is priced from CYBER.sol, the only side of the token with a
     * liquid market; the bridge holds them one-to-one.
     */
    private function cyberiaUsd(): ?float
    {
        $price = $this->cyberPrice->get()['priceUsd'] ?? null;

        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * @return array<string, float|null>
     */
    private function coingeckoUsd(): array
    {
        $missing = array_fill_keys(array_keys(self::COINGECKO_IDS), null);

        try {
            $response = Http::timeout(8)->get(self::COINGECKO_URL, [
                'ids' => implode(',', array_unique(array_values(self::COINGECKO_IDS))),
                'vs_currencies' => 'usd',
            ]);

            if ($response->failed()) {
                return $missing;
            }

            $body = $response->json();
        } catch (\Throwable) {
            return $missing;
        }

        $prices = [];

        foreach (self::COINGECKO_IDS as $chain => $id) {
            $price = $body[$id]['usd'] ?? null;
            $prices[$chain] = is_numeric($price) ? (float) $price : null;
        }

        return $prices;
    }
}
