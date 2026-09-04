<?php

namespace App\Console\Commands;

use App\Models\TrackerRelease;
use App\Services\Tracker\RegistrationFailed;
use App\Services\Tracker\ReleaseRegistrar;
use Illuminate\Console\Command;

/**
 * Re-read who owns each release.
 *
 * The only field on a release that changes without anyone touching this server
 * is the owner: the token is transferable, and a sold release moves with it.
 * Everything else is fixed by the CID the token points at, so this asks the
 * chain one question per row and writes nothing else.
 *
 * A token whose owner cannot be read is left exactly as it was. An RPC that
 * blinked is not a transfer, and treating it as one would rewrite the
 * authorship of the index every time the node restarts.
 */
class TrackerSyncCommand extends Command
{
    protected $signature = 'tracker:sync {--limit=100 : Releases to re-read in this run}';

    protected $description = 'Refresh release ownership from the chain';

    public function handle(ReleaseRegistrar $registrar): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $moved = 0;
        $unread = 0;

        $releases = TrackerRelease::query()
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($releases as $release) {
            $chain = (array) config("tracker.chains.{$release->chain_id}", []);

            if (($chain['rpc_url'] ?? '') === '') {
                continue;
            }

            try {
                $owner = strtolower($registrar->owner(
                    (string) $chain['rpc_url'],
                    $release->contract,
                    $release->token_id,
                ));
            } catch (RegistrationFailed) {
                $unread++;

                continue;
            }

            if ($owner === $release->owner) {
                // Touched anyway, so the ordering above rotates through the
                // index instead of re-reading the same hundred rows each run.
                $release->touch();

                continue;
            }

            $release->forceFill(['owner' => $owner])->save();
            $moved++;
        }

        $this->info("Read {$releases->count()} releases; {$moved} changed hands, {$unread} unreadable.");

        return self::SUCCESS;
    }
}
