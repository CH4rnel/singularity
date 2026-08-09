<?php

namespace App\Console\Commands;

use App\Models\LaunchpadToken;
use App\Services\LaunchpadSiteService;
use Illuminate\Console\Command;

/**
 * Publish token sites to IPFS.
 *
 * Uploads are pinned as they happen, so on a healthy host this finds nothing.
 * It exists for the two cases that leave a page hosted but not addressed by
 * content: sites uploaded before pinning existed, and sites uploaded while the
 * node was down. `--force` re-pins everything, which is also how a fresh node
 * takes over the whole set (pinning the same bytes yields the same CID).
 */
class LaunchpadPinSitesCommand extends Command
{
    protected $signature = 'launchpad:pin-sites {--force : Re-pin sites that already have a CID}';

    protected $description = 'Pin uploaded Launchpad token sites to IPFS and record their CIDs';

    public function handle(LaunchpadSiteService $sites): int
    {
        $query = LaunchpadToken::query()->whereNotNull('html_path');

        if (! $this->option('force')) {
            $query->whereNull('ipfs_cid');
        }

        $tokens = $query->orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->info('Nothing to pin.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;

        foreach ($tokens as $token) {
            $cid = $sites->publish($token);

            if ($cid === null) {
                $failed++;
            }

            $rows[] = [
                $token->chain_id,
                $token->address,
                $token->site_subdomain ?? '—',
                $cid ?? 'FAILED',
            ];
        }

        $this->table(['Chain', 'Address', 'Subdomain', 'CID'], $rows);
        $this->info(($tokens->count() - $failed).' pinned, '.$failed.' failed.');

        // A failure here is an unreachable node, not a bad row: the next run
        // picks the same sites up again.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
