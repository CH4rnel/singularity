<?php

namespace App\Console\Commands;

use App\Services\CrmSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:sync
    {--balances : Also refresh cached on-chain balances}
    {--balances-only : Skip imports and only refresh cached on-chain balances}
    {--limit=100 : Max contacts to refresh balances for}')]
#[Description('Import CRM contacts or refresh their cached on-chain wallet balances')]
class CrmSyncCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CrmSyncService $sync): int
    {
        $balancesOnly = (bool) $this->option('balances-only');

        if (! $balancesOnly) {
            $counts = $sync->syncAll();

            $this->info(sprintf(
                'Imported: %d platform users, %d bridge addresses, %d holders, %d whales.',
                $counts['platform'],
                $counts['bridge'],
                $counts['holders'],
                $counts['whales'],
            ));
        }

        if ($balancesOnly || $this->option('balances')) {
            $limit = max(1, (int) $this->option('limit'));
            $refreshed = $sync->refreshBalances($limit);
            $this->info("Refreshed on-chain balances for {$refreshed} contacts.");
        }

        return self::SUCCESS;
    }
}
