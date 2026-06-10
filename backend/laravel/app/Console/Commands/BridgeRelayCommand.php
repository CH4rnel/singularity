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
    {--all-failed : Retry every request currently marked failed}
    {--force : Re-run even if status is processing or completed}')]
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

        if (! $force && in_array($request->status, ['processing', 'completed'], true)) {
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
            'status' => 'pending',
            'error_message' => null,
        ]);

        ProcessBridgeRequest::dispatchSync($request->id);

        $request->refresh();

        $colour = match ($request->status) {
            'completed' => 'info',
            'failed' => 'error',
            default => 'warn',
        };

        $this->{$colour}(sprintf(
            '  → status=%s%s%s',
            $request->status,
            $request->destination_tx_hash ? '  dest_tx='.$request->destination_tx_hash : '',
            $request->error_message ? '  error='.$request->error_message : '',
        ));

        return $request->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }

    private function retryAllFailed(): int
    {
        $failed = BridgeRequest::where('status', 'failed')->orderBy('id')->get();

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
        $stuck = BridgeRequest::whereIn('status', ['pending', 'processing', 'failed'])
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
    }
}
