<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bridge:relay
    {id? : BridgeRequest id (omit to list stuck requests)}
    {--tx= : Look up by source_tx_hash instead of id}
    {--all-failed : Retry every failed / awaiting-liquidity / burn-pending request}
    {--force : Re-run a request stuck in processing (NEVER re-pays a broadcast payout)}')]
#[Description('Retry a stuck bridge request — resets it to pending and runs ProcessBridgeRequest synchronously.')]
class BridgeRelayCommand extends Command
{
    public function handle(): int
    {
        if ($this->option('all-failed')) {
            return $this->retryAllFailed();
        }

        $request = $this->resolveRequest();

        if (! $request) {
            $this->showStuckList();

            return self::SUCCESS;
        }

        return $this->retry($request);
    }

    private function resolveRequest(): ?BridgeRequest
    {
        if ($tx = $this->option('tx')) {
            $req = BridgeRequest::where('source_tx_hash', $tx)->first();

            if (! $req) {
                $this->error("No bridge request with source_tx_hash={$tx}");
            }

            return $req;
        }

        $id = $this->argument('id');

        if (! $id) {
            return null;
        }

        $req = BridgeRequest::find($id);

        if (! $req) {
            $this->error("BridgeRequest #{$id} not found");
        }

        return $req;
    }

    private function retry(BridgeRequest $request): int
    {
        $force = (bool) $this->option('force');

        // `--force` overrides the operator's own caution, never the ledger. A
        // request whose payout has already been broadcast is not re-runnable
        // by anybody: the money left this server once and the hash proves it.
        // Bridge request #68 was recovered by hand precisely because a blind
        // re-run is how one incident becomes two.
        if ($request->isCompleted() || $request->hasPayout()) {
            $this->error(sprintf(
                'Request #%d already has a payout (%s). It will not be paid again%s.',
                $request->id,
                $request->destination_tx_hash ?: 'completed',
                $force ? ', not even with --force' : '',
            ));

            // A payout that landed but whose wrapper burn is still owed is the
            // one thing a retry here CAN finish, and it never re-pays.
            if ($request->isBurnPending()) {
                $this->line('  finishing the outstanding wrapper burn…');

                return $this->finish($request);
            }

            return self::FAILURE;
        }

        if (! $force && $request->status === BridgeRequest::PROCESSING) {
            $this->error("Request #{$request->id} is {$request->status}. Use --force to re-run anyway.");

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Retrying #%d  %s  %s  amount=%s  recipient=%s',
            $request->id,
            $request->direction,
            $request->token,
            $request->amount,
            $request->recipient_address,
        ));

        if ($request->error_message) {
            $this->line('  previous error: '.$request->error_message);
        }

        $request->update([
            'status' => BridgeRequest::PENDING,
            'error_message' => null,
        ]);

        return $this->finish($request);
    }

    /**
     * Hand the request to the one relay path there is — the same job the queue
     * runs, so a manual retry cannot take a shortcut past a lock or a capacity
     * check.
     */
    private function finish(BridgeRequest $request): int
    {
        ProcessBridgeRequest::dispatchSync($request->id);

        $request->refresh();

        $colour = match ($request->status) {
            BridgeRequest::COMPLETED => 'info',
            BridgeRequest::FAILED => 'error',
            default => 'warn',
        };

        $this->{$colour}(sprintf(
            '  → status=%s%s%s',
            $request->status,
            $request->destination_tx_hash ? '  dest_tx='.$request->destination_tx_hash : '',
            $request->error_message ? '  error='.$request->error_message : '',
        ));

        return $request->isCompleted() ? self::SUCCESS : self::FAILURE;
    }

    private function retryAllFailed(): int
    {
        // Everything a retry can still move forward: an outright failure, a
        // deposit parked for liquidity, and a payout whose wrapper burn is
        // owed. Not `completed`, and not anything already holding a payout
        // hash that has nothing left to finish.
        $failed = BridgeRequest::whereIn('status', [
            BridgeRequest::FAILED,
            BridgeRequest::AWAITING_LIQUIDITY,
            BridgeRequest::BURN_PENDING,
        ])->orderBy('id')->get();

        if ($failed->isEmpty()) {
            $this->info('No failed bridge requests.');

            return self::SUCCESS;
        }

        $this->info("Retrying {$failed->count()} failed request(s)…");

        $ok = 0;
        $still = 0;

        foreach ($failed as $request) {
            $this->newLine();
            $code = $this->retry($request);

            if ($code === self::SUCCESS) {
                $ok++;
            } else {
                $still++;
            }
        }

        $this->newLine();
        $this->info("Done. completed={$ok}  still_failed={$still}");

        return $still === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function showStuckList(): void
    {
        $stuck = BridgeRequest::whereIn('status', [
            BridgeRequest::PENDING,
            BridgeRequest::PROCESSING,
            BridgeRequest::AWAITING_LIQUIDITY,
            BridgeRequest::PAYING_OUT,
            BridgeRequest::BURN_PENDING,
            BridgeRequest::FAILED,
        ])
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'direction', 'token', 'status', 'amount', 'sender_address', 'recipient_address', 'source_tx_hash', 'error_message', 'created_at']);

        if ($stuck->isEmpty()) {
            $this->info('No stuck bridge requests.');

            return;
        }

        $this->info('Stuck bridge requests (most recent 50):');
        $this->newLine();

        $this->table(
            ['id', 'created', 'direction', 'token', 'status', 'amount', 'error / dest'],
            $stuck->map(fn ($r) => [
                $r->id,
                $r->created_at?->format('Y-m-d H:i'),
                $r->direction,
                $r->token,
                $r->status,
                (string) $r->amount,
                $r->error_message ? \Str::limit($r->error_message, 60) : ($r->destination_tx_hash ?? '—'),
            ])->all(),
        );

        $this->newLine();
        $this->line('Run with id to retry one:   <fg=cyan>php artisan bridge:relay {id}</>');
        $this->line('Or retry every failure:     <fg=cyan>php artisan bridge:relay --all-failed</>');
        $this->line('A row holding a payout hash is never paid twice, --force included.');
    }
}
