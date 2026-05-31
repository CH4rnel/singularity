<?php

namespace App\Console\Commands;

use App\Models\SlotPoolToken;
use App\Services\Slots\PumpfunDiscoveryService;
use App\Services\Slots\SlotPoolService;
use Illuminate\Console\Command;

/**
 * Bulk-imports the top N pump.fun coins by market cap into the active slot
 * pool's whitelist. Runs hourly on the schedule; safe to re-run manually.
 *
 * Tokens previously imported via pump.fun_bulk that no longer appear in the
 * top-N are *disabled* if they have zero balance, so the reels reflect the
 * current pump.fun market. Tokens with on-chain balance are left as-is —
 * we never strip a token that holds player funds.
 */
class SlotsImportPumpfunCommand extends Command
{
    protected $signature = 'slots:import-pumpfun
        {--top= : Maximum entries to fetch; defaults to SLOT_PUMPFUN_BULK_TOP_N}
        {--min-mcap= : Floor USD market cap; defaults to SLOT_PUMPFUN_MIN_MCAP_USD}';

    protected $description = 'Bulk-import top pump.fun coins (by market cap) into the active slot pool whitelist.';

    public function handle(PumpfunDiscoveryService $pumpfun, SlotPoolService $pools): int
    {
        $pool = $pools->activePool();

        if (! $pool) {
            $this->error('No active slot pool found.');

            return self::FAILURE;
        }

        $top = (int) ($this->option('top') ?: config('services.slots.pumpfun_bulk_top_n', 200));
        $minMcap = (int) ($this->option('min-mcap') ?: config('services.slots.pumpfun_min_mcap_usd', 10000));

        $this->info("Fetching top {$top} pump.fun coins with mcap >= \${$minMcap}...");

        $coins = $pumpfun->listTop($top, $minMcap);

        if ($coins === []) {
            $this->warn('Pump.fun discovery returned no coins; aborting.');

            return self::SUCCESS;
        }

        $autoEnable = (bool) config('services.slots.pumpfun_auto_enable', true);
        $imported = 0;
        $failed = 0;
        $seenMints = [];

        foreach ($coins as $coin) {
            try {
                $pools->whitelistToken(
                    pool: $pool,
                    mint: $coin['mint'],
                    autoEnable: $autoEnable,
                    source: SlotPoolToken::SOURCE_PUMPFUN_BULK,
                    extras: [
                        'pumpfun_market_cap_usd' => isset($coin['usd_market_cap']) ? (string) $coin['usd_market_cap'] : null,
                        'pumpfun_last_seen_at' => now(),
                    ],
                );
                $seenMints[] = $coin['mint'];
                $imported++;
            } catch (\Throwable $e) {
                $this->warn("Skip {$coin['mint']}: ".$e->getMessage());
                $failed++;
            }
        }

        // Disable stale bulk entries (not in this batch + zero balance).
        $stale = SlotPoolToken::query()
            ->where('slot_pool_id', $pool->id)
            ->where('source', SlotPoolToken::SOURCE_PUMPFUN_BULK)
            ->whereNotIn('mint', $seenMints ?: ['__none__'])
            ->where('current_balance', '0')
            ->where('enabled', true)
            ->update(['enabled' => false]);

        $this->info("Imported {$imported}, failed {$failed}, disabled-stale {$stale}.");

        return self::SUCCESS;
    }
}
