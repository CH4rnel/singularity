<?php

namespace App\Console\Commands;

use App\Models\AiApiRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Drop metering rows past the retention window.
 *
 * The log exists to enforce a daily quota and to answer "what did this key
 * spend"; neither question reaches back months. Keeping the rows anyway would
 * only build a long record of who called what and when, which is not something
 * this API set out to hold.
 */
class AiPruneUsageCommand extends Command
{
    protected $signature = 'ai:prune-usage {--days= : override the configured retention}';

    protected $description = 'Delete inference API usage rows older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('ai.usage_retention_days', 90));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $deleted = AiApiRequest::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} usage rows older than {$days} days.");

        return self::SUCCESS;
    }
}
