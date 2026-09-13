<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use App\Services\BridgeDepositCredit;
use App\Services\BridgeDepositWatcher;
use Illuminate\Console\Command;

/**
 * Credit the deposits nobody is sitting there waiting for.
 *
 * A corridor whose deposit is bound to an address rather than to a signed
 * transaction has no event to react to: the coins arrive on the chain, and
 * this server finds out only by looking. Yenten could get away with the user
 * pressing a button, because one confirmation is about a minute. Monero
 * cannot — ten confirmations is twenty minutes, and a bridge that only works
 * while a browser tab stays open is a bridge that strands people's money in a
 * wallet they cannot see.
 *
 * So this is the other half of the claim endpoint, and deliberately the same
 * code underneath: `BridgeDepositCredit` decides, this only chooses who to ask
 * about. Reading is free and idempotent — a request already credited is no
 * longer awaiting a deposit, so it is not in the set at all.
 */
class BridgeSweepDepositsCommand extends Command
{
    protected $signature = 'bridge:sweep-deposits
        {--chain= : Only this source chain (monero, yenten)}
        {--limit=25 : How many requests to look at in one run}';

    protected $description = 'Credit deposits that landed on one-time bridge addresses (Monero, Yenten)';

    public function handle(BridgeDepositCredit $credit, BridgeDepositWatcher $watcher): int
    {
        $chains = collect(config('bridge.chains', []))
            ->filter(fn (array $chain) => $watcher->supports($chain))
            ->keys()
            ->all();

        if ($only = $this->option('chain')) {
            $chains = array_values(array_intersect($chains, [(string) $only]));

            if ($chains === []) {
                $this->error("'{$only}' is not a chain whose deposits land on a one-time address.");

                return self::FAILURE;
            }
        }

        // Oldest first: a deposit that has been waiting longest is the one
        // closest to its window closing, and the run is capped so a backlog
        // drains over several runs instead of holding the scheduler open.
        $requests = BridgeRequest::query()
            ->where('status', BridgeRequest::AWAITING_DEPOSIT)
            ->whereIn('source_chain', $chains)
            ->whereNotNull('deposit_address')
            ->oldest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($requests->isEmpty()) {
            $this->info('No deposit addresses are being watched.');

            // Still run the resume pass: a request that was credited is no
            // longer awaiting a deposit, so an empty watch list above says
            // nothing about whether one is stuck below.
            $this->resumeStranded($chains);

            return self::SUCCESS;
        }

        $credited = 0;
        $expired = 0;

        foreach ($requests as $request) {
            $outcome = $credit->credit($request);

            if ($outcome->credited) {
                $credited++;
                $this->info("#{$request->id} {$request->source_chain}: credited {$request->fresh()->amount} {$request->token}");

                continue;
            }

            if ($outcome->expired) {
                $expired++;
                $this->line("#{$request->id} {$request->source_chain}: window closed, nothing arrived");

                continue;
            }

            $this->line("#{$request->id} {$request->source_chain}: {$outcome->message}");
        }

        $resumed = $this->resumeStranded($chains);

        $this->info("Looked at {$requests->count()}: {$credited} credited, {$expired} expired, {$resumed} resumed.");

        return self::SUCCESS;
    }

    /**
     * Requests whose deposit was credited and whose relay never started.
     *
     * Crediting is three writes and a relay, and the relay is the part that
     * talks to another chain — so there is a window where the coins are taken,
     * the obligation is on the books, and the payout has not begun. Nothing
     * else looks at `pending`: the sweep above watches `awaiting_deposit`,
     * `bridge:relay` is a command somebody types, and the browser that started
     * it may be closed. On 2026-09-13 that window swallowed a real transfer
     * for an hour, and only because somebody was watching the spinner.
     *
     * Re-running the relay is safe by construction: `hasPayout()` makes a
     * second payout unreachable, so the worst a needless resume costs is one
     * read of the destination chain.
     *
     * @param  array<int, string>  $chains
     */
    private function resumeStranded(array $chains): int
    {
        // Old enough that a relay still running inside the request that
        // started it is not interrupted — a Monero payout alone is allowed
        // four minutes.
        $idleMinutes = max(1, (int) config('bridge.relay.resume_after_minutes', 10));

        $stranded = BridgeRequest::query()
            ->where('status', BridgeRequest::PENDING)
            ->whereIn('source_chain', $chains)
            ->whereNotNull('deposit_address')
            ->where('updated_at', '<=', now()->subMinutes($idleMinutes))
            // A handful per run: each one relays inline, and a queue of them
            // must not hold the two-minute schedule open.
            ->oldest('id')
            ->limit(3)
            ->get();

        foreach ($stranded as $request) {
            $this->warn("#{$request->id} {$request->source_chain}: credited but never relayed — resuming.");

            ProcessBridgeRequest::dispatchSync($request->id);

            $this->line("#{$request->id}: now {$request->fresh()->status}");
        }

        return $stranded->count();
    }
}
