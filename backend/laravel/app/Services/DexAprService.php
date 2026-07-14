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

    /** MasterChef farm (Farm.vue reads the same contract client-side). */
    private const MASTERCHEF = '0xd540DEa828567160FFDe5e792ca359aDD1f6B03D';

    /** ~1s Cyberia blocks. */
    private const BLOCKS_PER_YEAR = 86400 * 365;

    public static function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function snapshot(): array
    {
        $prices = $this->tokenPrices();

        $data = [
            'updated_at' => now('UTC')->toIso8601String(),
            'window_hours' => 24,
            'pools' => $this->computePools($prices),
            'farms' => $this->computeFarms($prices),
        ];

        // Forever: a stale snapshot beats an empty page; updated_at lets
        // consumers surface staleness if they care.
        Cache::forever(self::CACHE_KEY, $data);

        return $data;
    }

    /** @return array<string, float> token address (lowercase) => USD price */
    private function tokenPrices(): array
    {
        return Schema::hasTable('token_prices')
            ? DB::table('token_prices')
                ->whereNotNull('price_usd')
                ->pluck('price_usd', 'address')
                ->mapWithKeys(fn ($p, $a) => [strtolower($a) => (float) $p])
                ->all()
            : [];
    }

    private function computePools(array $prices): array
    {
        if (! Schema::hasTable('dex_pools')) {
            return [];
        }

        $pools = DB::table('dex_pools')->get();

        if ($pools->isEmpty()) {
            return [];
        }

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

    /**
     * Farm APY per active MasterChef pool: annual reward emission valued in
     * USD against the USD value of what's staked. This is the same math
     * Farm.vue runs client-side (there in CYBER terms), so the landing quotes
     * the number farmers actually see.
     */
    private function computeFarms(array $prices): array
    {
        $chef = strtolower(config('services.dex.masterchef', self::MASTERCHEF));

        $poolLength = (int) ($this->callUint($chef, '0x081e3eda') ?? 0); // poolLength()
        $totalAlloc = $this->callUint($chef, '0x17caf6f1');              // totalAllocPoint()
        $rewardPerBlock = $this->callUint($chef, '0x8ae39cac');          // rewardPerBlock()
        $rewardToken = $this->callAddress($chef, '0xf7c618c1');          // rewardToken()

        if ($poolLength === 0 || ! $totalAlloc || $rewardPerBlock === null || $rewardToken === null) {
            return [];
        }

        $pairs = Schema::hasTable('dex_pools')
            ? DB::table('dex_pools')->get()->keyBy(fn ($p) => strtolower($p->pair_address))
            : collect();

        $rewardPrice = $this->resolvePrice($rewardToken, $prices, $pairs);
        $rewardPerYearUsd = $rewardPrice !== null
            ? $rewardPerBlock / 10 ** $this->tokenDecimals($rewardToken) * self::BLOCKS_PER_YEAR * $rewardPrice
            : null;
        $symbols = Schema::hasTable('token_prices')
            ? DB::table('token_prices')
                ->whereNotNull('symbol')
                ->pluck('symbol', 'address')
                ->mapWithKeys(fn ($s, $a) => [strtolower($a) => $s])
                ->all()
            : [];

        // Emission against a near-empty stake produces a headline number that
        // evaporates on the first real deposit — not worth advertising.
        $minStaked = (float) config('services.dex.apr_min_farm_tvl_usd', 1);

        $farms = [];

        for ($pid = 0; $pid < $poolLength; $pid++) {
            // poolInfo(uint256) → (lpToken, allocPoint, lastRewardBlock, accRewardPerShare)
            $info = $this->rpc('eth_call', [
                ['to' => $chef, 'data' => '0x1526fe27'.str_pad(dechex($pid), 64, '0', STR_PAD_LEFT)],
                'latest',
            ]);

            if (! is_string($info) || strlen($info) < 2 + 128) {
                continue;
            }

            $words = str_split(substr($info, 2), 64);
            $lpToken = '0x'.substr($words[0], 24);
            $allocPoint = (float) hexdec($words[1]);

            if ($allocPoint <= 0) {
                continue; // retired pool
            }

            $staked = $this->callUint($lpToken, '0x70a08231'.str_pad(substr($chef, 2), 64, '0', STR_PAD_LEFT));
            $pair = $pairs[$lpToken] ?? null;

            if ($pair !== null) {
                $supply = $this->callUint($lpToken, '0x18160ddd'); // totalSupply()
                $stakedUsd = ($supply ?? 0.0) > 0
                    ? (float) ($pair->tvl_usd ?? 0) * ($staked ?? 0.0) / $supply
                    : 0.0;
                $label = "{$pair->symbol0}/{$pair->symbol1} LP";
            } else {
                $price = $this->resolvePrice($lpToken, $prices, $pairs);
                $stakedUsd = $price !== null && $staked !== null
                    ? $staked / 10 ** $this->tokenDecimals($lpToken) * $price
                    : null;
                $label = $symbols[$lpToken] ?? substr($lpToken, 0, 6).'…'.substr($lpToken, -4);
            }

            $share = $allocPoint / $totalAlloc;
            $apy = $rewardPerYearUsd !== null && $stakedUsd !== null && $stakedUsd >= $minStaked
                ? round($rewardPerYearUsd * $share / $stakedUsd * 100, 2)
                : null;

            $farms[] = [
                'pid' => $pid,
                'label' => $label,
                'staked_usd' => $stakedUsd !== null ? round($stakedUsd, 2) : null,
                'reward_share' => round($share, 4),
                'apy' => $apy,
            ];
        }

        usort($farms, fn ($a, $b) => ($b['apy'] ?? -1) <=> ($a['apy'] ?? -1));

        return $farms;
    }

    /**
     * USD price of a token: the bot's token_prices first, else derived from
     * any dex_pools pair whose other side is priced (spot from reserves —
     * the same fallback Farm.vue uses when the price walker comes up empty,
     * e.g. for a freshly launched reward token like ASH).
     */
    private function resolvePrice(string $token, array $prices, $pairs): ?float
    {
        if (isset($prices[$token])) {
            return $prices[$token];
        }

        foreach ($pairs as $pair) {
            $t0 = strtolower((string) $pair->token0);
            $t1 = strtolower((string) $pair->token1);
            $r0 = (float) ($pair->reserve0 ?? 0);
            $r1 = (float) ($pair->reserve1 ?? 0);

            if ($token === $t0 && isset($prices[$t1]) && $r0 > 0) {
                return $r1 * $prices[$t1] / $r0;
            }

            if ($token === $t1 && isset($prices[$t0]) && $r1 > 0) {
                return $r0 * $prices[$t0] / $r1;
            }
        }

        return null;
    }

    private function callUint(string $to, string $data): ?float
    {
        $result = $this->rpc('eth_call', [['to' => $to, 'data' => $data], 'latest']);

        return is_string($result) && $result !== '0x'
            ? (float) hexdec(substr($result, 2))
            : null;
    }

    private function callAddress(string $to, string $data): ?string
    {
        $result = $this->rpc('eth_call', [['to' => $to, 'data' => $data], 'latest']);

        return is_string($result) && strlen($result) === 66
            ? '0x'.substr($result, 26)
            : null;
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
