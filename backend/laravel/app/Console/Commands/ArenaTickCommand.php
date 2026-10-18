<?php

namespace App\Console\Commands;

use App\Services\Arena\ArenaAutomation;
use Illuminate\Console\Command;

class ArenaTickCommand extends Command
{
    protected $signature = 'arena:tick';

    protected $description = 'Advance the configured paper season once; gated, bounded, no transaction signing';

    public function handle(ArenaAutomation $automation): int
    {
        $state = $automation->tick();
        $this->line($state);

        return in_array($state, ['automation_failed', 'market_unavailable', 'fill_unavailable', 'both_models_failed', 'missing_model_key', 'budget_exhausted', 'free_models_required'], true)
            ? self::FAILURE : self::SUCCESS;
    }
}
