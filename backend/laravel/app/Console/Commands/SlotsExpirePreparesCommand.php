<?php

namespace App\Console\Commands;

use App\Models\SlotSpin;
use Illuminate\Console\Command;

class SlotsExpirePreparesCommand extends Command
{
    protected $signature = 'slots:expire-prepares';

    protected $description = 'Mark prepared slot spins past their TTL as expired so the wallet can spin again';

    public function handle(): int
    {
        $expired = SlotSpin::where('status', SlotSpin::STATUS_PREPARED)
            ->where('expires_at', '<', now())
            ->update([
                'status' => SlotSpin::STATUS_EXPIRED,
                'error_message' => 'TTL exceeded',
            ]);

        $this->info("Expired {$expired} spin(s).");

        return self::SUCCESS;
    }
}
