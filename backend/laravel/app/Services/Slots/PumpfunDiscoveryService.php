<?php

namespace App\Services\Slots;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around pump.fun's public frontend API
 * (https://frontend-api-v3.pump.fun) used purely for token discovery.
 *
 * Two methods only:
 *   - listTop()   – paginated top-by-market-cap, with mcap floor.
 *   - verifyMint() – is this mint a pump.fun coin? Used by the lazy
 *                    auto-whitelist path so any pump.fun token a user wants
 *                    to bet "just works".
 *
 * The pump.fun SDK (@pump-fun/pump-sdk) is a transaction-builder, not an
 * indexer, so the only realistic way to enumerate or verify tokens is their
 * HTTP API. No API key needed; soft rate limit on their side.
 *
 * Failures here never propagate — discovery problems must not block the
 * slot machine itself. Callers receive [] or null and continue.
 */
class PumpfunDiscoveryService
{
    private const PAGE_SIZE = 50;

    private const RETRY_DELAYS_MS = [500, 1000, 2000];

    public function base(): string
    {
        return rtrim((string) config('services.slots.pumpfun_api_base'), '/');
    }

    /**
     * Paginated top coins by market cap. Stops at `$limit` accepted entries
     * or when a page falls below `$minMcapUsd` (list is descending).
     *
     * @return list<array{mint:string,symbol:?string,name:?string,image_uri:?string,market_cap:float,usd_market_cap:?float}>
     */
    public function listTop(int $limit, int $minMcapUsd): array
    {
        $accepted = [];
        $offset = 0;

        while (count($accepted) < $limit) {
            $response = $this->request('GET', '/coins', [
                'offset' => $offset,
                'limit' => self::PAGE_SIZE,
                'sort' => 'market_cap',
                'order' => 'DESC',
                'includeNsfw' => 'false',
            ]);

            if ($response === null) {
                break;
            }

            $page = $response->json();

            if (! is_array($page) || $page === []) {
                break;
            }

            $stopped = false;

            foreach ($page as $coin) {
                $mcap = (float) ($coin['usd_market_cap'] ?? $coin['market_cap'] ?? 0);

                if ($mcap < $minMcapUsd) {
                    $stopped = true;
                    break;
                }

                if (empty($coin['mint'])) {
                    continue;
                }

                $accepted[] = [
                    'mint' => (string) $coin['mint'],
                    'symbol' => isset($coin['symbol']) ? (string) $coin['symbol'] : null,
                    'name' => isset($coin['name']) ? (string) $coin['name'] : null,
                    'image_uri' => isset($coin['image_uri']) ? (string) $coin['image_uri'] : null,
                    'market_cap' => $mcap,
                    'usd_market_cap' => isset($coin['usd_market_cap']) ? (float) $coin['usd_market_cap'] : null,
                ];

                if (count($accepted) >= $limit) {
                    break;
                }
            }

            if ($stopped || count($page) < self::PAGE_SIZE) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }

        return $accepted;
    }

    /**
     * Returns the coin meta if pump.fun knows about this mint, null otherwise.
     * `null` covers both "not a pump.fun token" (404) and "API unavailable".
     *
     * @return array<string,mixed>|null
     */
    public function verifyMint(string $mint): ?array
    {
        $response = $this->request('GET', '/coins/'.rawurlencode($mint));

        if ($response === null) {
            return null;
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        return is_array($body) && ! empty($body['mint']) ? $body : null;
    }

    private function request(string $method, string $path, array $query = []): ?Response
    {
        $url = $this->base().$path;

        foreach (self::RETRY_DELAYS_MS as $attempt => $delayMs) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'cyberia-slots/1.0'])
                    ->timeout(15)
                    ->{strtolower($method)}($url, $query);
            } catch (\Throwable $e) {
                Log::warning('Pumpfun: request exception', ['url' => $url, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                $this->sleep($delayMs);

                continue;
            }

            // 404 is a definitive answer for verifyMint — don't retry.
            if ($response->status() === 404) {
                return $response;
            }

            if ($response->successful()) {
                return $response;
            }

            // 429/5xx → retry.
            Log::warning('Pumpfun: non-success response', ['url' => $url, 'status' => $response->status(), 'attempt' => $attempt]);
            $this->sleep($delayMs);
        }

        return null;
    }

    protected function sleep(int $ms): void
    {
        usleep($ms * 1000);
    }
}
