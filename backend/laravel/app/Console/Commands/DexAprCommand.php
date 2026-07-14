<?php

namespace App\Console\Commands;

use App\Services\DexAprService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dex:apr')]
#[Description('Scan 24h of on-chain swaps and cache per-pool LP APR')]
class DexAprCommand extends Command
{
    public function handle(DexAprService $apr): int
    {
        $snapshot = $apr->snapshot();
        $pools = collect($snapshot['pools']);
        $farms = collect($snapshot['farms']);

        $this->info(sprintf(
            'APR snapshot: %d pools, 24h volume $%s, best APR %s%%.',
            $pools->count(),
            number_format($pools->sum('volume_24h_usd'), 2),
            $pools->first()['apr'] ?? '—',
        ));
        $this->info(sprintf(
            'Farms: %d active, top APY %s%%.',
            $farms->count(),
            $farms->first()['apy'] ?? '—',
        ));

        return self::SUCCESS;
    }
}
