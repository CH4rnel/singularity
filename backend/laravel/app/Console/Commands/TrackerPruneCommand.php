<?php

namespace App\Console\Commands;

use App\Models\TrackerPeer;
use App\Models\TrackerRelease;
use Illuminate\Console\Command;

/**
 * Forget peers that stopped announcing, and say so on the releases.
 *
 * Announce already sweeps the swarm it is announcing to, so this is not what
 * keeps the peer table honest — it is what keeps a *silent* swarm honest. A
 * release everybody left is never announced to again, so nothing would ever
 * recount it, and it would sit on the index showing the four seeders it had
 * the day the last one closed their client.
 */
class TrackerPruneCommand extends Command
{
    protected $signature = 'tracker:prune';

    protected $description = 'Drop expired tracker peers and recount every swarm';

    public function handle(): int
    {
        $dropped = TrackerPeer::query()->where('expires_at', '<', now())->delete();

        $counts = TrackerPeer::query()
            ->where('expires_at', '>=', now())
            ->selectRaw('info_hash, sum(case when seeder = 1 then 1 else 0 end) as seeders, sum(case when seeder = 1 then 0 else 1 end) as leechers')
            ->groupBy('info_hash')
            ->get()
            ->keyBy('info_hash');

        $changed = 0;

        TrackerRelease::query()->chunkById(200, function ($releases) use ($counts, &$changed) {
            foreach ($releases as $release) {
                $row = $counts->get($release->info_hash);
                $seeders = (int) ($row->seeders ?? 0);
                $leechers = (int) ($row->leechers ?? 0);

                if ($release->seeders === $seeders && $release->leechers === $leechers) {
                    continue;
                }

                $release->forceFill(['seeders' => $seeders, 'leechers' => $leechers])->save();
                $changed++;
            }
        });

        $this->info("Dropped {$dropped} peers; recounted {$changed} releases.");

        return self::SUCCESS;
    }
}
