<?php

namespace App\Console\Commands;

use App\Services\Arena\ArenaMarketFeed;
use Illuminate\Console\Command;
use Throwable;

class ArenaProbeCommand extends Command
{
    protected $signature = 'arena:probe';

    protected $description = 'Read and verify the public reference market without model calls, writes or signing';

    public function handle(ArenaMarketFeed $market): int
    {
        try {
            $quote = $market->quote($market->specification(), (int) config('arena.automation.max_block_age_seconds'));
        } catch (Throwable) {
            $this->error('Market unavailable or failed verification. No inference or transactions were attempted.');

            return self::FAILURE;
        }
        $this->line(json_encode($quote, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
