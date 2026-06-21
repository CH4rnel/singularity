<?php

namespace App\Console\Commands;

use App\Services\CrmSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crm:sync {--balances : Also refresh cached on-chain balances} {--limit=100 : Max contacts to refresh balances for}')]
#[Description('Import CRM contacts from platform users, bridge activity and the whale gate')]
class CrmSyncCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CrmSyncService $sync): int
    {
        $counts = $sync->syncAll();

        $this->info(sprintf(
            'Imported: %d platform users, %d bridge addresses, %d whales.',
            $counts['platform'],
            $counts['bridge'],
            $counts['whales'],
        ));

        if ($this->option('balances')) {
            $refreshed = $sync->refreshBalances((int) $this->option('limit'));
            $this->info("Refreshed on-chain balances for {$refreshed} contacts.");
        }

        return self::SUCCESS;
    }
}
