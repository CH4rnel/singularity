<?php

namespace App\Jobs;

use App\Models\BridgeRequest;
use App\Services\BridgeEventLogger;
use App\Services\BridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessBridgeRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $bridgeRequestId,
        public ?string $sessionId = null,
    ) {}

    public function handle(BridgeService $bridgeService, BridgeEventLogger $eventLogger): void
    {
        $request = BridgeRequest::find($this->bridgeRequestId);

        if (! $request || ! $request->isPending()) {
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
        } elseif ($request->status === 'failed') {
            $this->logEvent($eventLogger, 'relayer_failed', $request, $request->error_message);
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
