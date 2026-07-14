<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Per-pool LP APR from real on-chain volume.
 *
 * Scans the last ~24h of UniswapV2 Swap events on every known pair (the
 * dex_pools snapshot the Telegram bot maintains), prices the traded amounts
 * with the bot's token_prices table, and annualizes the 0.3% LP fee against
 * pool TVL. The chain caps eth_getLogs at 1000 blocks per call and Cyberia
 * mines ~1 block/s, so a full day is ~87 chunked calls — too slow for a
 * request, hence the artisan `dex:apr` command computes and caches the
 * snapshot on the scheduler and readers only ever see the cache.
 */
class DexAprService
{
    public const CACHE_KEY = 'dex.apr.snapshot';

    /** UniswapV2 Swap(address,uint256,uint256,uint256,uint256,address). */
    private const SWAP_TOPIC = '0xd78ad95fa46c994b6551d0da85fc275fe613ce37657fb8d5e3d130840159d822';

    /** LP fee share of volume on the QuickSwap fork (0.3%, no protocol cut). */
    private const FEE_RATE = 0.003;

    public static function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function snapshot(): array
    {
        $data = [
            'updated_at' => now('UTC')->toIso8601String(),
            'window_hours' => 24,
            'pools' => $this->computePools(),
        ];

        // Forever: a stale snapshot beats an empty page; updated_at lets
        // consumers surface staleness if they care.
        Cache::forever(self::CACHE_KEY, $data);

        return $data;
    }

    private function computePools(): array
    {
        if (! Schema::hasTable('dex_pools')) {
            return [];
        }

        $pools = DB::table('dex_pools')->get();

        if ($pools->isEmpty()) {
            return [];
        }

        $prices = Schema::hasTable('token_prices')
            ? DB::table('token_prices')
                ->whereNotNull('price_usd')
                ->pluck('price_usd', 'address')
                ->mapWithKeys(fn ($p, $a) => [strtolower($a) => (float) $p])
                ->all()
            : [];

        $volumes = $this->volumeByPair(
            $pools->pluck('pair_address')->map(fn ($a) => strtolower($a))->all(),
            $pools->keyBy(fn ($p) => strtolower($p->pair_address)),
            $prices,
        );

        // Dust pools (1-wei reserves) turn any routed-through volume into a
        // millions-of-percent APR; below the floor the number is meaningless
        // and would headline the landing, so it stays null.
        $minTvl = (float) config('services.dex.apr_min_tvl_usd', 10);

        $rows = $pools->map(function ($pool) use ($volumes, $minTvl) {
            $pair = strtolower($pool->pair_address);
            $volume = $volumes[$pair] ?? 0.0;
            $fees = $volume * self::FEE_RATE;
            $tvl = (float) ($pool->tvl_usd ?? 0);

            return [
                'pair_address' => $pair,
                'symbol0' => $pool->symbol0,
                'symbol1' => $pool->symbol1,
                'tvl_usd' => $tvl,
                'volume_24h_usd' => round($volume, 2),
                'fees_24h_usd' => round($fees, 4),
                'apr' => $tvl >= $minTvl ? round($fees * 365 / $tvl * 100, 2) : null,
            ];
        });

        return $rows
            ->sortByDesc(fn ($row) => $row['apr'] ?? -1)
            ->values()
            ->all();
    }

    /** @return array<string, float> pair address (lowercase) => 24h USD volume */
    private function volumeByPair(array $pairAddresses, $poolsByPair, array $prices): array
    {
        $head = $this->blockNumber();

        if ($head === null) {
            return [];
        }

        $windowBlocks = (int) config('services.dex.apr_window_blocks', 86400);
        $chunkBlocks = (int) config('services.dex.apr_chunk_blocks', 1000);
        $from = max(0, $head - $windowBlocks + 1);

        $decimals = [];
        $volumes = [];

        while ($from <= $head) {
            $to = min($from + $chunkBlocks - 1, $head);

            foreach ($this->getLogs($pairAddresses, $from, $to) as $log) {
                $pair = strtolower($log['address'] ?? '');
                $pool = $poolsByPair[$pair] ?? null;
                $data = substr((string) ($log['data'] ?? ''), 2);

                if ($pool === null || strlen($data) < 256) {
                    continue;
                }

                [$a0in, $a1in, $a0out, $a1out] = array_map(
                    fn (int $i) => (float) hexdec(substr($data, $i * 64, 64)),
                    [0, 1, 2, 3],
                );

                $t0 = strtolower($pool->token0);
                $t1 = strtolower($pool->token1);
                $dec0 = $decimals[$t0] ??= $this->tokenDecimals($t0);
                $dec1 = $decimals[$t1] ??= $this->tokenDecimals($t1);
                $p0 = $prices[$t0] ?? null;
                $p1 = $prices[$t1] ?? null;

                // One side of a swap is always a single token; value whichever
                // sides we can price and take the larger (they're ~equal).
                $inUsd = ($p0 !== null ? $a0in / 10 ** $dec0 * $p0 : 0)
                    + ($p1 !== null ? $a1in / 10 ** $dec1 * $p1 : 0);
                $outUsd = ($p0 !== null ? $a0out / 10 ** $dec0 * $p0 : 0)
                    + ($p1 !== null ? $a1out / 10 ** $dec1 * $p1 : 0);
                $usd = max($inUsd, $outUsd);

                if ($usd > 0) {
                    $volumes[$pair] = ($volumes[$pair] ?? 0.0) + $usd;
                }
            }

            $from = $to + 1;
        }

        return $volumes;
    }

    private function blockNumber(): ?int
    {
        $result = $this->rpc('eth_blockNumber', []);

        return is_string($result) ? (int) hexdec($result) : null;
    }

    private function getLogs(array $addresses, int $from, int $to): array
    {
        $result = $this->rpc('eth_getLogs', [[
            'fromBlock' => '0x'.dechex($from),
            'toBlock' => '0x'.dechex($to),
            'address' => $addresses,
            'topics' => [self::SWAP_TOPIC],
        ]]);

        return is_array($result) ? $result : [];
    }

    private function tokenDecimals(string $address): int
    {
        return Cache::rememberForever('dex.token_decimals.'.$address, function () use ($address) {
            $result = $this->rpc('eth_call', [
                ['to' => $address, 'data' => '0x313ce567'], // decimals()
                'latest',
            ]);

            return is_string($result) && $result !== '0x'
                ? (int) hexdec(substr($result, -8))
                : 18;
        });
    }

    private function rpc(string $method, array $params): mixed
    {
        $url = config('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');

        $response = Http::timeout(30)->post($url, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ]);

        return $response->ok() ? $response->json('result') : null;
    }
}
