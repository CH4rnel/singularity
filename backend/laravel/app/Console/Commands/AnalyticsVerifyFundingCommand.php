<?php

namespace App\Console\Commands;

use App\Models\AnalyticsAddress;
use App\Models\AnalyticsUser;
use App\Services\Analytics\AnalyticsIngestService;
use App\Services\Analytics\FundingVerifier;
use Illuminate\Console\Command;

/**
 * Catch the funding the browser never got to report.
 *
 * The wallet reports a candidate the moment it sees a balance, which covers
 * the usual case — somebody is sitting there watching for the coin to land.
 * It does not cover the rest: a wallet that was funded while closed and opened
 * once, briefly, on a phone with no signal; a deposit that confirmed after the
 * tab was gone; a wallet funded from an exchange hours later. Those are the
 * users an activation funnel most wants to know about, because a wallet that
 * was funded and never came back is the drop-off worth fixing.
 *
 * So this walks the installations that linked an address, have not been marked
 * funded, and were seen recently enough to still matter, and asks the chain
 * directly. Bounded per run: this is a scheduled job on a shared host, not a
 * chain scanner.
 */
class AnalyticsVerifyFundingCommand extends Command
{
    protected $signature = 'analytics:verify-funding
        {--limit= : Addresses to check in this run}
        {--days= : Only users seen within this many days}';

    protected $description = 'Confirm wallet funding on chain for anonymous analytics users';

    public function handle(FundingVerifier $verifier, AnalyticsIngestService $ingest): int
    {
        if (! $ingest->enabled()) {
            $this->warn('Analytics is disabled (ANALYTICS_ENABLED).');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?? config('analytics.funding_sweep_limit', 200));
        $days = (int) ($this->option('days') ?? config('analytics.funding_sweep_days', 14));

        $candidates = AnalyticsAddress::query()
            ->join('analytics_users', 'analytics_users.id', '=', 'analytics_addresses.user_id')
            ->whereNull('analytics_users.funded_at')
            ->where('analytics_users.last_seen_at', '>=', now('UTC')->subDays($days))
            ->orderBy('analytics_addresses.id')
            ->limit($limit)
            ->get(['analytics_addresses.user_id', 'analytics_addresses.chain', 'analytics_addresses.address']);

        $checked = 0;
        $funded = 0;

        foreach ($candidates as $candidate) {
            $user = AnalyticsUser::find($candidate->user_id);

            // Another address on the same installation may have answered first
            // inside this very loop; the milestone is write-once either way.
            if ($user === null || $user->funded_at !== null) {
                continue;
            }

            $checked++;

            if (! $verifier->hasBalance($candidate->chain, $candidate->address)) {
                continue;
            }

            $ingest->stampFunded($user, $candidate->chain, 'onchain');
            $funded++;
        }

        $this->info("Checked {$checked} address(es); {$funded} newly funded.");

        return self::SUCCESS;
    }
}
