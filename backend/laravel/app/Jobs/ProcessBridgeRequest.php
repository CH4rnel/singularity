<?php

namespace App\Jobs;

use App\Models\BridgeRequest;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeEventLogger;
use App\Services\BridgeService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one path a bridge request is ever relayed through — from the queue, from
 * a retry, and from `bridge:relay` in an operator's terminal.
 *
 * Three things keep two of those from happening at once:
 *
 *  1. `ShouldBeUnique` keeps a second copy of this job off the queue at all.
 *  2. A cache lock on the request id keeps a queued job and a hand-run command
 *     from overlapping, which uniqueness cannot see.
 *  3. {@see BridgeRequest::claimForProcessing()} moves the row out of a
 *     processable state in one atomic statement, so even two holders of
 *     different locks cannot both proceed.
 *
 * The timeout is deliberately larger than the slowest relay subprocess, and
 * the queue's `retry_after` larger still — a job released mid-payout is a
 * double payment, not a retry.
 */
class ProcessBridgeRequest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * How long the queue lets one attempt run. Must exceed the longest relay
     * subprocess (a payout followed by a burn), and must itself be exceeded by
     * the connection's `retry_after`.
     */
    public int $timeout = 660;

    /**
     * Only one copy of this request in the queue, for as long as an attempt
     * could plausibly still be running.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public int $bridgeRequestId,
        public ?string $sessionId = null,
    ) {
        $this->timeout = max(60, (int) config('bridge.relay.job_timeout_seconds', 660));
        $this->uniqueFor = $this->timeout + 60;
    }

    public function uniqueId(): string
    {
        return (string) $this->bridgeRequestId;
    }

    /**
     * Growing gaps: a destination RPC that is down is rarely back in a second,
     * and a request parked awaiting liquidity needs the operator's minutes,
     * not the queue's.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(BridgeService $bridgeService, BridgeEventLogger $eventLogger): void
    {
        $lock = Cache::lock(
            'bridge:request:'.$this->bridgeRequestId,
            (int) config('bridge.inventory.request_lock_seconds', 600),
        );

        if (! $lock->get()) {
            Log::info('Bridge: request is already being processed elsewhere', [
                'id' => $this->bridgeRequestId,
            ]);

            return;
        }

        try {
            $this->relay($bridgeService, $eventLogger);
        } finally {
            $lock->release();
        }
    }

    private function relay(BridgeService $bridgeService, BridgeEventLogger $eventLogger): void
    {
        $request = BridgeRequest::find($this->bridgeRequestId);

        if (! $request) {
            return;
        }

        // Atomic: whoever flips the row out of a processable state is the one
        // that relays it. Everyone else returns having done nothing.
        if (! $request->claimForProcessing()) {
            Log::info('Bridge: request was not in a processable state', [
                'id' => $request->id,
                'status' => $request->status,
            ]);

            return;
        }

        Log::info('Bridge: Processing request', [
            'id' => $request->id,
            'direction' => $request->direction,
            'token' => $request->token,
        ]);

        // Direct array access — `config('bridge.tokens.CYBER.sol')` would treat
        // the dot in 'CYBER.sol' as a nested key separator.
        $tokenConfig = config('bridge.tokens', [])[$request->token] ?? null;

        if (! \is_array($tokenConfig)) {
            $request->markFailed("Unknown token: {$request->token}");

            $this->logEvent($eventLogger, 'relayer_failed', $request, $request->error_message);

            return;
        }

        $route = config('bridge.routes', [])[$request->direction] ?? null;

        if (! \is_array($route) || ($route['auto_process'] ?? false) !== true) {
            Log::info('Bridge: route is pending manual relay', [
                'id' => $request->id,
                'direction' => $request->direction,
                'token' => $request->token,
            ]);

            // Hand the row back rather than leaving it stuck in `processing`:
            // a manual-relay corridor is waiting on a person, not on us.
            $request->update(['status' => BridgeRequest::PENDING]);

            return;
        }

        $this->logEvent($eventLogger, 'relayer_started', $request);

        $model = $tokenConfig['model'] ?? 'native';

        if ($model === 'direct' || $model === 'mint') {
            $bridgeService->processDirectRelay($request);
        } else {
            match ($request->direction) {
                'sol_to_evm' => $bridgeService->processSolToEvm($request),
                'evm_to_sol' => $bridgeService->processEvmToSol($request),
                default => $request->markFailed("Unknown direction: {$request->direction}"),
            };
        }

        $request->refresh();

        if ($request->isCompleted()) {
            $this->logEvent($eventLogger, 'relayer_succeeded', $request);
        } elseif ($request->status === BridgeRequest::FAILED) {
            $this->logEvent($eventLogger, 'relayer_failed', $request, $request->error_message);
        }
    }

    /**
     * The attempt died — timed out, ran out of memory, lost the worker. Leave
     * the row in a state that says what is true and that a retry can act on,
     * never stranded in `processing` where nothing will pick it up again.
     */
    public function failed(?\Throwable $exception): void
    {
        $request = BridgeRequest::find($this->bridgeRequestId);

        if (! $request) {
            return;
        }

        Log::error('Bridge: relay job failed', [
            'id' => $request->id,
            'status' => $request->status,
            'error' => $exception?->getMessage(),
        ]);

        // A payout already left this server: the request keeps its hash and
        // whatever unfinished accounting it has. Marking it failed would
        // invite an operator to retry it into a second payment.
        if ($request->hasPayout()) {
            if (! $request->isCompleted() && ! $request->isBurnPending()) {
                $request->update([
                    'status' => BridgeRequest::PAYING_OUT,
                    'error_message' => 'Relay worker died after broadcasting the payout — reconciling on retry',
                ]);
            }

            return;
        }

        if ($request->status === BridgeRequest::PROCESSING) {
            $request->markFailed(
                'Relay worker stopped before a payout: '.($exception?->getMessage() ?: 'unknown error'),
            );
            app(BridgeAdmissionService::class)
                ->releaseFor($request, 'relay worker died before a verified deposit');
        }
    }

    private function logEvent(BridgeEventLogger $eventLogger, string $type, BridgeRequest $request, ?string $error = null): void
    {
        if (! $this->sessionId) {
            return;
        }

        $eventLogger->log($type, [
            'session_id' => $this->sessionId,
            'user_id' => $request->user_id,
            'bridge_request_id' => $request->id,
            'direction' => $request->direction,
            'amount' => $request->amount,
            'source_address' => $request->sender_address,
            'destination_address' => $request->recipient_address,
            'error_message' => $error,
        ]);
    }
}
