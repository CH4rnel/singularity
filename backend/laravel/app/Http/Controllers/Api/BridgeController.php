<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use App\Rules\ValidDestinationAddress;
use App\Services\BridgeConfigService;
use App\Services\BridgeEventLogger;
use App\Services\BridgeFeeService;
use App\Services\BridgeService;
use App\Services\CyberiaRpcService;
use App\Services\TonApiService;
use App\Services\Yenten\YentenAddressDeriver;
use App\Services\YentenApiService;
use App\Support\TokenAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BridgeController extends Controller
{
    public function __construct(
        private BridgeService $bridgeService,
        private BridgeEventLogger $eventLogger,
        private BridgeFeeService $feeService,
        private BridgeConfigService $bridgeConfig,
        private CyberiaRpcService $rpc,
    ) {}

    /**
     * Submit a bridge request after the user has locked tokens on the source chain.
     */
    public function submit(Request $request): JsonResponse
    {
        $direction = $request->input('direction');
        $supportedTokens = array_keys(config('bridge.tokens', []));
        $routes = config('bridge.routes', []);
        $supportedDirections = array_keys($routes);

        $validated = $request->validate([
            'direction' => ['required', Rule::in($supportedDirections)],
            'token' => ['nullable', 'string', 'in:'.implode(',', $supportedTokens)],
            'source_tx_hash' => ['required', 'string'],
            'source_nonce' => ['required', 'integer', 'min:0'],
            'sender_address' => ['required', 'string'],
            'recipient_address' => [
                'required',
                'string',
                new ValidDestinationAddress(is_string($direction) ? $direction : ''),
            ],
            'amount' => ['required', 'numeric', 'gt:0'],
            'convert_to_native' => ['nullable', 'boolean'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        $route = $routes[$validated['direction']] ?? null;

        if (! is_array($route)) {
            return response()->json(['message' => 'Unsupported bridge direction.'], 422);
        }

        $sourceChain = (string) $route['source_chain'];
        $token = $validated['token'] ?? 'CYBER.sol';

        if (! isset($this->bridgeConfig->tokensForRoute($validated['direction'])[$token])) {
            return response()->json(['message' => 'Token is not supported on this bridge route.'], 422);
        }

        $destinationChain = $this->bridgeConfig->chain((string) $route['destination_chain']);

        if (($sourceChain === 'yenten' || ($destinationChain['key'] ?? null) === 'yenten')
            && $this->bridgeConfig->depositAddress('yenten') === null) {
            return response()->json(['message' => 'Yenten bridge address is not configured.'], 422);
        }

        $yentenChain = $this->bridgeConfig->chain('yenten');

        if (($sourceChain === 'yenten' || ($destinationChain['type'] ?? null) === 'yenten')
            && empty($yentenChain['relayer_wif'])) {
            return response()->json(['message' => 'Yenten bridge relayer is not configured.'], 422);
        }

        // Chain-specific guards above give precise messages; anything else
        // filtered out of availableRoutes() gets the generic refusal.
        if (! isset($this->bridgeConfig->availableRoutes()[$validated['direction']])) {
            return response()->json(['message' => 'This bridge route is not currently available.'], 422);
        }

        // Auto-conversion into native CYBER only applies to CYBER.sol arriving
        // on Cyberia, and only when the feature is enabled server-side.
        $convertToNative = ($validated['convert_to_native'] ?? false)
            && $validated['direction'] === 'sol_to_evm'
            && $token === 'CYBER.sol'
            && config('bridge.convert.enabled', true);

        $fee = $this->feeService->feeForBridge(
            $token,
            (string) $validated['amount'],
            $validated['direction'],
        );

        [$gasDropPlanned, $gasDropAmount] = $this->planGasDrop(
            $validated['direction'],
            $validated['recipient_address'],
        );

        // Normalize TON hashes (hex vs base64 encodings of the same tx) so the
        // source_tx_hash unique constraint can't be replayed via re-encoding.
        $sourceTxHash = $validated['source_tx_hash'];

        if ($sourceChain === 'ton') {
            $normalized = TonApiService::normalizeTxHash($sourceTxHash);

            if ($normalized === null) {
                return response()->json(['message' => 'Invalid TON transaction hash.'], 422);
            }

            $sourceTxHash = $normalized;
        }

        try {
            $bridgeRequest = $this->bridgeService->createRequest(
                userId: $request->user()?->id,
                direction: $validated['direction'],
                sourceChain: $sourceChain,
                sourceTxHash: $sourceTxHash,
                sourceNonce: $validated['source_nonce'],
                senderAddress: $validated['sender_address'],
                recipientAddress: $validated['recipient_address'],
                amount: $validated['amount'],
                token: $token,
                feeAmount: $fee['fee_amount'],
                feeUsd: $fee['fee_usd'],
                gasDropPlanned: $gasDropPlanned,
                gasDropAmount: $gasDropAmount,
                convertToNative: $convertToNative,
            );
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'message' => 'This transaction has already been submitted to the bridge.',
            ], 422);
        }

        if (! empty($validated['session_id'])) {
            $this->eventLogger->log('bridge_request_created', [
                'session_id' => $validated['session_id'],
                'user_id' => $request->user()?->id,
                'bridge_request_id' => $bridgeRequest->id,
                'direction' => $validated['direction'],
                'amount' => $validated['amount'],
                'source_address' => $validated['sender_address'],
                'destination_address' => $validated['recipient_address'],
                'metadata' => ['token' => $token],
            ], $request);
        }

        if ($this->shouldAutoProcess($validated['direction'])) {
            ProcessBridgeRequest::dispatchSync($bridgeRequest->id, $validated['session_id'] ?? null);
        }

        $bridgeRequest->refresh();

        return response()->json([
            'message' => $bridgeRequest->isCompleted() ? 'Bridge completed' : 'Bridge request submitted',
            'bridge_request' => [
                'id' => $bridgeRequest->id,
                'direction' => $bridgeRequest->direction,
                'token' => $bridgeRequest->token,
                'status' => $bridgeRequest->status,
                'amount' => $bridgeRequest->amount,
                'fee_amount' => $bridgeRequest->fee_amount,
                'fee_usd' => $bridgeRequest->fee_usd,
                'gas_drop_planned' => $bridgeRequest->gas_drop_planned,
                'gas_drop_amount' => $bridgeRequest->gas_drop_amount,
                'convert_to_native' => $bridgeRequest->convert_to_native,
                'converted' => $bridgeRequest->converted,
                'destination_tx_hash' => $bridgeRequest->destination_tx_hash,
                'error_message' => $bridgeRequest->error_message,
                'created_at' => $bridgeRequest->created_at,
            ],
        ], 201);
    }

    /**
     * Phase 1 of a one-time-address deposit route (Yenten): commit the
     * recipient and hand back a unique deposit address derived for this
     * request. Binding a deposit to a single request with a pre-committed
     * recipient is what prevents hijacking a public deposit transaction.
     */
    public function prepare(Request $request): JsonResponse
    {
        $direction = $request->input('direction');
        $routes = $this->bridgeConfig->availableRoutes();

        $validated = $request->validate([
            'direction' => ['required', Rule::in(array_keys($routes))],
            'token' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('bridge.tokens', [])))],
            'recipient_address' => [
                'required',
                'string',
                new ValidDestinationAddress(is_string($direction) ? $direction : ''),
            ],
            'session_id' => ['nullable', 'uuid'],
        ]);

        $route = $routes[$validated['direction']];
        $sourceChain = $this->bridgeConfig->chain((string) $route['source_chain']);

        // One-time deposit addresses are only wired for Yenten today.
        if (($sourceChain['type'] ?? null) !== 'yenten') {
            return response()->json([
                'message' => 'This route does not use a prepared deposit address.',
            ], 422);
        }

        $token = $validated['token'] ?? 'YTN';

        if (! isset($this->bridgeConfig->tokensForRoute($validated['direction'])[$token])) {
            return response()->json(['message' => 'Token is not supported on this bridge route.'], 422);
        }

        // Amount is unknown until the user deposits — 0 for now, set at claim
        // time from the detected balance.
        $bridgeRequest = $this->bridgeService->createRequest(
            userId: $request->user()?->id,
            direction: $validated['direction'],
            sourceChain: (string) $route['source_chain'],
            sourceTxHash: null,
            sourceNonce: 0,
            senderAddress: null,
            recipientAddress: $validated['recipient_address'],
            amount: '0',
            token: $token,
            status: 'awaiting_deposit',
        );

        // Derive the one-time address + its spending key from the request id,
        // and store both (WIF encrypted) so the operator can move the deposit.
        $deriver = YentenAddressDeriver::fromConfig();
        $bridgeRequest->update([
            'deposit_address' => $deriver->depositAddress($bridgeRequest->id),
            'deposit_wif' => $deriver->childWif($bridgeRequest->id),
        ]);

        return response()->json([
            'bridge_request' => [
                'id' => $bridgeRequest->id,
                'direction' => $bridgeRequest->direction,
                'token' => $bridgeRequest->token,
                'deposit_address' => $bridgeRequest->deposit_address,
                'recipient_address' => $bridgeRequest->recipient_address,
                'status' => $bridgeRequest->status,
                'expires_at' => $bridgeRequest->created_at
                    ->addMinutes($this->yentenDepositTtlMinutes())
                    ->toIso8601String(),
            ],
        ], 201);
    }

    private function yentenDepositTtlMinutes(): int
    {
        return max(1, (int) config('bridge.chains.yenten.deposit_ttl_minutes', 60));
    }

    /**
     * Phase 2: the user has sent (any amount of) coins to the request's
     * one-time address. Detect the balance on that address and mint exactly
     * that much to the committed recipient — no amount or tx hash from the user.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        $bridgeRequest = BridgeRequest::find($validated['id']);

        // Expired requests answer without touching the Yenten API: the whole
        // point of the deposit window is to stop polling dead addresses.
        if ($bridgeRequest && $bridgeRequest->isExpired()) {
            return response()->json([
                'message' => 'The deposit window has expired and this address is no longer monitored — start a new transfer.',
                'expired' => true,
            ], 422);
        }

        if (! $bridgeRequest || ! $bridgeRequest->isAwaitingDeposit()) {
            return response()->json(['message' => 'Bridge request is not awaiting a deposit.'], 422);
        }

        $depositAddress = (string) $bridgeRequest->deposit_address;
        $chain = $this->bridgeConfig->chain((string) $bridgeRequest->source_chain);
        $tokenChain = config('bridge.tokens', [])[$bridgeRequest->token]['chains'][$bridgeRequest->source_chain] ?? null;

        // Read what actually landed on the address.
        $balances = app(YentenApiService::class)->addressBalances($depositAddress);

        if ($balances === null) {
            return response()->json([
                'message' => 'Could not reach the Yenten network — try again shortly.',
                'retryable' => true,
            ], 422);
        }

        $balanceRaw = $balances['confirmed'];

        if (bccomp($balanceRaw, '0', 0) <= 0) {
            // The deposit is visible but unconfirmed — say so instead of
            // pretending nothing arrived. Applies inside or past the window:
            // coins already in flight are always honored.
            if (bccomp($balances['pending'], '0', 0) > 0) {
                return response()->json([
                    'message' => 'Deposit detected — waiting for a Yenten network confirmation (about a minute). Try again shortly.',
                    'retryable' => true,
                ], 422);
            }

            // Nothing at all: inside the window keep waiting, past it close
            // the request for good so the address drops out of polling.
            $expiresAt = $bridgeRequest->created_at->addMinutes($this->yentenDepositTtlMinutes());

            if ($expiresAt->isPast()) {
                $bridgeRequest->markExpired();

                return response()->json([
                    'message' => 'The deposit window has expired and this address is no longer monitored — start a new transfer.',
                    'expired' => true,
                ], 422);
            }

            return response()->json([
                'message' => 'No deposit detected yet — send YTN to the address and try again.',
                'retryable' => true,
            ], 422);
        }

        // Set the amount to the detected balance (scaled from Yenten satoshis)
        // and process — the relayer mints exactly this much.
        $decimals = (int) ($tokenChain['decimals'] ?? 8);
        $bridgeRequest->update([
            'amount' => TokenAmount::fromRaw($balanceRaw, $decimals),
            'status' => 'pending',
        ]);

        if ($this->shouldAutoProcess($bridgeRequest->direction)) {
            ProcessBridgeRequest::dispatchSync($bridgeRequest->id, $validated['session_id'] ?? null);
        }

        $bridgeRequest->refresh();

        // Mint failed (e.g. transient RPC) — revert to awaiting so the user can
        // retry once the deposit confirms. Funds stay safe on the address.
        if ($bridgeRequest->status === 'failed') {
            $bridgeRequest->update([
                'status' => 'awaiting_deposit',
                'amount' => '0',
                'error_message' => null,
            ]);

            return response()->json([
                'message' => 'Deposit not confirmed yet — wait a moment and try again.',
                'retryable' => true,
            ], 422);
        }

        return response()->json([
            'bridge_request' => [
                'id' => $bridgeRequest->id,
                'direction' => $bridgeRequest->direction,
                'token' => $bridgeRequest->token,
                'status' => $bridgeRequest->status,
                'destination_tx_hash' => $bridgeRequest->destination_tx_hash,
                'error_message' => $bridgeRequest->error_message,
            ],
        ]);
    }

    private function shouldAutoProcess(string $direction): bool
    {
        $route = config('bridge.routes', [])[$direction] ?? null;

        return is_array($route) && ($route['auto_process'] ?? false) === true;
    }

    /**
     * Decide whether a sol_to_evm bridge should also drop native CYBER on the
     * recipient so they can pay for their first transaction. Returns
     * [planned, amount].
     *
     * @return array{0: bool, 1: string|null}
     */
    private function planGasDrop(string $direction, string $recipient): array
    {
        $route = config('bridge.routes', [])[$direction] ?? null;

        if (! is_array($route) || ($route['destination_chain'] ?? null) !== 'cyberia') {
            return [false, null];
        }

        if (! config('bridge.gas_drop.enabled', true)) {
            return [false, null];
        }

        $balance = $this->rpc->nativeBalanceWei($recipient);

        if ($balance === null) {
            return [false, null];
        }

        $threshold = (string) config('bridge.gas_drop.threshold_wei', '0');

        if (bccomp($balance, $threshold, 0) > 0) {
            return [false, null];
        }

        return [true, (string) config('bridge.gas_drop.amount_cyber', '0.01')];
    }

    /**
     * Get the status of a bridge request.
     */
    public function status(BridgeRequest $bridgeRequest): JsonResponse
    {
        return response()->json([
            'id' => $bridgeRequest->id,
            'direction' => $bridgeRequest->direction,
            'source_chain' => $bridgeRequest->source_chain,
            'source_tx_hash' => $bridgeRequest->source_tx_hash,
            'sender_address' => $bridgeRequest->sender_address,
            'recipient_address' => $bridgeRequest->recipient_address,
            'amount' => $bridgeRequest->amount,
            'status' => $bridgeRequest->status,
            'convert_to_native' => $bridgeRequest->convert_to_native,
            'converted' => $bridgeRequest->converted,
            'destination_tx_hash' => $bridgeRequest->destination_tx_hash,
            'error_message' => $bridgeRequest->error_message,
            'created_at' => $bridgeRequest->created_at,
            'completed_at' => $bridgeRequest->completed_at,
        ]);
    }

    /**
     * List bridge requests for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $requests = BridgeRequest::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'direction', 'source_chain', 'source_tx_hash',
                'sender_address', 'recipient_address', 'amount',
                'status', 'destination_tx_hash', 'created_at', 'completed_at',
            ]);

        return response()->json(['data' => $requests]);
    }
}
