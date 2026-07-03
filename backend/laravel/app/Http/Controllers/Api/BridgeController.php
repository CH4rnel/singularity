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

        $fee = $this->feeService->feeForBridge($token, (string) $validated['amount']);

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
