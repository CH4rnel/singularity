<?php

namespace App\Console\Commands;

use App\Models\WalletChatMessage;
use Illuminate\Console\Command;

/**
 * Empty the chat relay of anything past its retention window.
 *
 * The relay is a queue, not an archive: the wallets at either end hold the
 * conversation, and a row here exists only so a wallet that was closed can
 * still collect its mail. Keeping ciphertext forever would build a permanent
 * record of who talked to whom — the one thing about these messages the server
 * *can* read — for no benefit to anyone using it.
 */
class WalletChatPruneCommand extends Command
{
    protected $signature = 'wallet:chat-prune';

    protected $description = 'Delete relayed wallet chat envelopes past the retention window';

    public function handle(): int
    {
        $days = (int) config('wallet.chat.retention_days');

        if ($days <= 0) {
            $this->warn('Retention is disabled (wallet.chat.retention_days <= 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $deleted = WalletChatMessage::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} envelope(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
