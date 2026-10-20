<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('bridge:reconcile
    {--limit=5 : How many transfers to move forward in one run}')]
#[Description('Carry unfinished bridge transfers forward without anybody watching: confirm broadcast payouts, finish burns, retry parked liquidity.')]
class BridgeReconcileCommand extends Command
{
    /**
     * The scheduled half of B07.
     *
     * A relay that returns false is not retried by the queue — false is an
     * answer, not an exception — and the browser that started it may be long
     * closed. So a payout whose receipt timed out sat in `paying_out` until an
     * operator happened to look, and `bridge:relay` refused it because it
     * holds a hash. This runs the same job over every row a retry can move,
     * and it is safe for the same reason that job is: a row with a payout
     * hash is only ever reconciled against the chain, never paid again.
     */
    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $this->recoverAbandoned();

        $idle = now()->subMinutes(max(1, (int) config('bridge.relay.resume_after_minutes', 10)));
        // A manual corridor waits on a person; the job would only hand it back.
        $automatic = collect(config('bridge.routes', []))
            ->filter(fn ($route) => is_array($route) && ($route['auto_process'] ?? false) === true)
            ->keys()
            ->all();

        // Oldest change first, so a row that stays parked yields its turn to
        // the next one instead of being asked about every run.
        $rows = BridgeRequest::query()
            ->whereIn('direction', $automatic)
            ->where(fn ($query) => $query
                ->whereIn('status', [
                    BridgeRequest::PAYING_OUT,
                    BridgeRequest::BURN_PENDING,
                    BridgeRequest::AWAITING_LIQUIDITY,
                ])
                // A pending row younger than this may still be inside the
                // submit that created it.
                ->orWhere(fn ($query) => $query
                    ->where('status', BridgeRequest::PENDING)
                    ->where('updated_at', '<=', $idle)))
            ->oldest('updated_at')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        foreach ($rows as $request) {
            $before = $request->status;
            ProcessBridgeRequest::dispatchSync($request->id);
            $request->refresh();

            $this->line(sprintf('#%d %s: %s → %s', $request->id, $request->direction, $before, $request->status));
        }

        return self::SUCCESS;
    }

    /**
     * A worker that died without reaching `failed()` (killed, out of memory,
     * host restarted) leaves its row in `processing`, which nothing picks up.
     * When the attempt is certainly over — older than a job may run, and its
     * request lock free — the row is handed back: to `paying_out` if a hash
     * was recorded, so the retry only reads the chain, otherwise to `pending`.
     */
    private function recoverAbandoned(): void
    {
        $limit = (int) config('bridge.relay.job_timeout_seconds', 660) + 60;

        $abandoned = BridgeRequest::query()
            ->where('status', BridgeRequest::PROCESSING)
            ->where('updated_at', '<=', now()->subSeconds($limit))
            ->get();

        foreach ($abandoned as $request) {
            $lock = Cache::lock('bridge:request:'.$request->id, 10);

            if (! $lock->get()) {
                continue;
            }

            try {
                $request->update([
                    'status' => $request->hasPayout() ? BridgeRequest::PAYING_OUT : BridgeRequest::PENDING,
                    'error_message' => 'Relay attempt was abandoned mid-run — reconciling',
                ]);
                $this->warn("#{$request->id}: abandoned in processing, handed back as {$request->status}");
            } finally {
                $lock->release();
            }
        }
    }
}
