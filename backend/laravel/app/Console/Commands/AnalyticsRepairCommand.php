<?php

namespace App\Console\Commands;

use App\Models\AnalyticsAddress;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsUser;
use App\Services\Analytics\EventTaxonomy;
use App\Services\Analytics\InternalTraffic;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Bring the milestones already in the table up to the rules that now apply.
 *
 * Two repairs, both of which the ingest path performs from here on, and
 * neither of which can be applied retroactively by an event that already
 * arrived:
 *
 *  - `wallet_created_at` on installations that were using a vault before this
 *    instrumentation existed. Without it the funnel shows people who funded
 *    and swapped without ever having a wallet, and `wallets` — the denominator
 *    of the funding rate — is zero while every step after it is not.
 *
 *  - `internal_at` on our own installations. The two people who build this
 *    wallet also test it, and until they are marked, every rate on the console
 *    is a description of their testing.
 *
 * Both are idempotent, both refuse to move a value that is already set, and
 * `--dry-run` prints exactly what would change. Milestones are write-once
 * everywhere else in this system; this is the one place allowed to write one
 * late, and it says so in `wallet_origin` by recording `existing` rather than
 * `created`.
 */
class AnalyticsRepairCommand extends Command
{
    protected $signature = 'analytics:repair
        {--dry-run : Report what would change and write nothing}';

    protected $description = 'Repair analytics milestones and mark internal installations';

    public function handle(InternalTraffic $internal): int
    {
        $dry = (bool) $this->option('dry-run');

        $wallets = $this->repairWallets($dry);
        $marked = $this->markInternal($internal, $dry);

        $this->newLine();
        $this->line($dry
            ? "Would stamp {$wallets} wallet milestone(s) and mark {$marked} internal installation(s)."
            : "Stamped {$wallets} wallet milestone(s), marked {$marked} internal installation(s).");

        if (! $dry) {
            $internal->forget();
        }

        return self::SUCCESS;
    }

    /**
     * Installations that proved a vault existed without ever announcing one.
     */
    private function repairWallets(bool $dry): int
    {
        $repaired = 0;

        AnalyticsUser::query()
            ->whereNull('wallet_created_at')
            ->orderBy('created_at')
            ->chunkById(200, function ($users) use (&$repaired, $dry) {
                foreach ($users as $user) {
                    /*
                     * The earliest event that could not have happened without
                     * a key. Dated to that event rather than to now: the claim
                     * is "the wallet existed by then", and a milestone stamped
                     * today would put every one of these installations in this
                     * week's cohort.
                     */
                    $proof = AnalyticsEvent::query()
                        ->where('user_id', $user->id)
                        ->whereIn('event', EventTaxonomy::PROVES_WALLET)
                        ->orderBy('created_at')
                        ->first(['event', 'created_at']);

                    if ($proof === null) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  wallet  %s  %s via %s',
                        substr($user->id, 0, 8),
                        Carbon::parse($proof->created_at)->toDateTimeString(),
                        $proof->event,
                    ));

                    if (! $dry) {
                        $user->forceFill([
                            'wallet_created_at' => $proof->created_at,
                            'wallet_origin' => 'existing',
                        ])->save();
                    }

                    $repaired++;
                }
            }, 'id');

        return $repaired;
    }

    /** Installations holding an address we know is ours. */
    private function markInternal(InternalTraffic $internal, bool $dry): int
    {
        $addresses = $internal->wallets();

        if ($addresses === []) {
            return 0;
        }

        $ids = AnalyticsAddress::query()
            ->whereIn('address', $addresses)
            ->distinct()
            ->pluck('user_id');

        $marked = 0;

        foreach ($ids as $id) {
            $user = AnalyticsUser::find($id);

            if ($user === null || $user->internal_at !== null) {
                continue;
            }

            $this->line('  ours    '.substr((string) $id, 0, 8));

            if (! $dry) {
                $user->forceFill([
                    'internal_at' => Carbon::now('UTC'),
                    'internal_reason' => 'address',
                ])->save();
            }

            $marked++;
        }

        return $marked;
    }
}
