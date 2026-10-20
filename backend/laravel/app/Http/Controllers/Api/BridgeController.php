<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBridgeRequest;
use App\Models\BridgeRequest;
use App\Models\BridgeReservation;
use App\Rules\ValidDestinationAddress;
use App\Services\BridgeAdmissionService;
use App\Services\BridgeConfigService;
use App\Services\BridgeDepositCredit;
use App\Services\BridgeDepositWatcher;
use App\Services\BridgeEventLogger;
use App\Services\BridgeFeeService;
use App\Services\BridgeService;
use App\Services\CyberiaRpcService;
use App\Services\TonApiService;
use App\Support\BridgeCapacity;
use App\Support\BridgeIntent;
use App\Support\BridgeSubmission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Live withdrawal capacity for a route+token: how much can be paid out to
     * the destination chain right now, minus everything already promised to
     * somebody else.
     *
     * The payload names its own state, because `available: null` used to mean
     * both "no ceiling" and "we could not read it" and the UI could not tell
     * them apart:
     *
     *   unlimited   — the relayer mints here; there is nothing to run out of.
     *   available   — a real number, with its raw integer form and decimals so
     *                 the browser can compare without touching a float.
     *   unmeasured  — manual reserves; this server does not read them.
     *   unavailable — the read failed. The UI must NOT let a signature happen.
     */
    public function capacity(Request $request, BridgeAdmissionService $admission): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['required', 'string'],
            'token' => ['required', 'string'],
        ]);

        $direction = $validated['direction'];
        $token = $validated['token'];

        // Only answer for corridors/tokens that are actually offered. A
        // corridor nobody may use has no capacity to quote — and saying
        // "unavailable" keeps the browser from treating silence as a ceiling.
        if (! isset($this->bridgeConfig->availableRoutes()[$direction])
            || ! isset($this->bridgeConfig->tokensForRoute($direction)[$token])) {
            return response()->json(
                BridgeCapacity::unavailable('this corridor is not currently available')->toArray()
            );
        }

        return response()->json($admission->availableCapacity($direction, $token)->toArray());
    }

    /**
     * Claim destination capacity BEFORE the user is asked to sign anything.
     *
     * This is the admission gate, and it is the server's, not the interface's.
     * A browser check cannot hold the invariant: between reading a balance and
     * signing a transfer there is a wallet prompt, a person, and possibly
     * another person doing exactly the same thing against the same reserve.
     * The reservation is written under a lock over the destination pool, so
     * two requests for 0.6 of a 1.0 balance cannot both be told yes.
     */
    public function reserve(Request $request, BridgeAdmissionService $admission): JsonResponse
    {
        $direction = $request->input('direction');
        $routes = $this->bridgeConfig->availableRoutes();

        $validated = $request->validate([
            'direction' => ['required', Rule::in(array_keys($routes))],
            'token' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('bridge.tokens', [])))],
            'sender_address' => ['nullable', 'string'],
            'recipient_address' => [
                'required',
                'string',
                new ValidDestinationAddress(is_string($direction) ? $direction : ''),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,18})?$/D'],
        ]);

        $token = $validated['token'] ?? 'CYBER.sol';

        if (! isset($this->bridgeConfig->tokensForRoute($validated['direction'])[$token])) {
            return response()->json(['message' => 'Token is not supported on this bridge route.'], 422);
        }

        $result = $admission->reserve(
            $validated['direction'],
            $token,
            (string) $validated['amount'],
            $validated['sender_address'] ?? null,
            $validated['recipient_address'],
        );

        if ($result['ok'] !== true) {
            return response()->json([
                'message' => $result['message'],
                'reason' => $result['reason'],
                'capacity' => $result['capacity']->toArray(),
            ], $result['reason'] === 'busy' ? 409 : 422);
        }

        $reservation = $result['reservation'];

        return response()->json([
            'reservation' => [
                'reference' => $reservation->reference,
                'direction' => $reservation->direction,
                'token' => $reservation->token,
                'amount' => $reservation->amount,
                'net_raw' => $reservation->net_raw,
                'decimals' => $reservation->decimals,
                'expires_at' => $reservation->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

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
            'amount' => ['required', 'numeric', 'gt:0', 'regex:/^\d+(?:\.\d{1,18})?$/D'],
            'convert_to_native' => ['nullable', 'boolean'],
            'reservation' => ['nullable', 'string', 'max:64'],
            'intent_signature' => ['nullable', 'string', 'max:200'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        $route = $routes[$validated['direction']] ?? null;

        if (! is_array($route)) {
            return response()->json(['message' => 'Unsupported bridge direction.'], 422);
        }

        $sourceChain = (string) $route['source_chain'];
        $token = $validated['token'] ?? 'CYBER.sol';

        $convertToNative = ($validated['convert_to_native'] ?? false)
            && $validated['direction'] === 'sol_to_evm'
            && $token === 'CYBER.sol';
        $sourceTxHash = BridgeSubmission::sourceHash($sourceChain, $validated['source_tx_hash']);

        if ($sourceChain === 'ton') {
            $sourceTxHash = TonApiService::normalizeTxHash($sourceTxHash);

            if ($sourceTxHash === null) {
                return response()->json(['message' => 'Invalid TON transaction hash.'], 422);
            }
        }

        $fingerprint = BridgeSubmission::fingerprint(
            $validated['direction'], $token, $sourceChain, $sourceTxHash,
            $validated['source_nonce'], $validated['sender_address'],
            $validated['recipient_address'], (string) $validated['amount'], $convertToNative,
        );

        // B03: verifying the transfer proves money arrived, not who may name
        // where it goes, so the sender has to sign the recipient. A claim
        // without a valid signature is not refused — the coins have moved
        // either way, and refusing would lose the only record of them — but
        // it is held, uncounted and unpaid, until somebody proves it.
        $requirement = BridgeIntent::requirement($validated['direction'], $token);
        $signedIntent = $requirement === BridgeIntent::SIGNATURE
            && filled($validated['intent_signature'] ?? null)
            && $this->intentIsSigned($validated, $token, $sourceChain, $sourceTxHash);
        $recipientProven = $requirement !== BridgeIntent::SIGNATURE || $signedIntent;
        $unprovenReason = filled($validated['intent_signature'] ?? null) ? 'intent_signature_invalid' : 'recipient_unproven';

        $existing = $this->existingSubmission($sourceChain, $sourceTxHash);

        // Somebody registered this deposit first without proving anything.
        // That is exactly the race B03 describes, and the depositor's signed
        // claim wins it: the unproven row is rewritten to what the sender
        // signed, and only then does anything move.
        $takeover = $existing !== null && $signedIntent
            && $existing->isAwaitingRecipient() && ! $existing->hasPayout();

        if ($existing !== null && ! $takeover) {
            return $this->repeatSubmission($existing, $fingerprint);
        }

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
        $convertToNative = $convertToNative && config('bridge.convert.enabled', true);

        $fee = $this->feeService->feeForBridge(
            $token,
            (string) $validated['amount'],
            $validated['direction'],
        );

        [$gasDropPlanned, $gasDropAmount] = $this->planGasDrop(
            $validated['direction'],
            $validated['recipient_address'],
        );

        $proof = [
            'submitted_intent_hash' => $fingerprint,
            'submitted_source_tx_hash' => $sourceTxHash,
            'recipient_proof' => $signedIntent ? BridgeIntent::SIGNATURE : ($recipientProven ? $requirement : null),
            'recipient_proven_at' => $signedIntent ? now() : null,
        ];

        try {
            $bridgeRequest = DB::transaction(function () use ($request, $validated, $sourceChain, $sourceTxHash, $token, $fee, $gasDropPlanned, $gasDropAmount, $convertToNative, $proof, $recipientProven, $unprovenReason, $takeover, $existing) {
                if ($takeover) {
                    $bridgeRequest = BridgeRequest::whereKey($existing->id)->lockForUpdate()->first();

                    if ($bridgeRequest === null || ! $bridgeRequest->isAwaitingRecipient() || $bridgeRequest->hasPayout()) {
                        return null;
                    }

                    $bridgeRequest->update([
                        'user_id' => $request->user()?->id ?? $bridgeRequest->user_id,
                        'direction' => $validated['direction'],
                        'token' => $token,
                        'source_chain' => $sourceChain,
                        'source_nonce' => $validated['source_nonce'],
                        'sender_address' => $validated['sender_address'],
                        'recipient_address' => $validated['recipient_address'],
                        'amount' => $validated['amount'],
                        'fee_amount' => $fee['fee_amount'],
                        'fee_usd' => $fee['fee_usd'],
                        'gas_drop_planned' => $gasDropPlanned,
                        'gas_drop_amount' => $gasDropAmount,
                        'convert_to_native' => $convertToNative,
                        'status' => BridgeRequest::PENDING,
                        'error_message' => null,
                        ...$proof,
                    ]);
                } else {
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
                        status: $recipientProven ? BridgeRequest::PENDING : BridgeRequest::AWAITING_RECIPIENT,
                    );
                    $bridgeRequest->update($proof + ($recipientProven ? [] : [
                        'error_message' => $unprovenReason === 'intent_signature_invalid'
                            ? 'Deposit recorded; the payout authorization did not verify against the sending wallet'
                            : 'Deposit recorded; the sending wallet has not signed its payout recipient',
                    ]));
                }

                // An unproven claim is not an obligation yet: counting it
                // against the pool would let anyone exhaust capacity with
                // hashes nobody will ever prove.
                if ($recipientProven) {
                    app(BridgeAdmissionService::class)->commit($bridgeRequest, $validated['reservation'] ?? null);
                }

                return $bridgeRequest;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->existingSubmission($sourceChain, $sourceTxHash);

            if ($existing === null) {
                throw $exception;
            }

            return $this->repeatSubmission($existing, $fingerprint);
        }

        if ($bridgeRequest === null) {
            // Lost a race for the same unproven row; whoever won has the
            // current answer.
            $current = $this->existingSubmission($sourceChain, $sourceTxHash);

            return $current !== null
                ? $this->repeatSubmission($current, $fingerprint)
                : response()->json(['message' => 'This transfer changed while it was being registered. Submit it again.'], 409);
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

        if (! $recipientProven) {
            return $this->submissionResponse($bridgeRequest, false, 202, $unprovenReason);
        }

        if ($this->shouldAutoProcess($validated['direction'])) {
            ProcessBridgeRequest::dispatchSync($bridgeRequest->id, $validated['session_id'] ?? null);
        }

        $bridgeRequest->refresh();

        return $this->submissionResponse($bridgeRequest, false);
    }

    /**
     * Did the sending wallet sign this payout recipient for this transfer?
     *
     * Two references are accepted, and both are single-use: the reservation
     * the transfer was claimed under, signed before anything was sent — only
     * while that reservation is still unconsumed and was made for this same
     * sender, recipient, corridor and token — or the source hash itself,
     * which the depositor can sign afterwards to claim a deposit that was
     * never registered.
     *
     * @param  array<string, mixed>  $validated
     */
    private function intentIsSigned(array $validated, string $token, string $sourceChain, string $sourceTxHash): bool
    {
        $chainType = (string) config("bridge.chains.{$sourceChain}.type", '');
        $sign = fn (string $reference) => BridgeIntent::signedBy(
            $chainType,
            (string) $validated['sender_address'],
            BridgeIntent::message(
                $validated['direction'],
                $token,
                (string) $validated['sender_address'],
                (string) $validated['recipient_address'],
                $reference,
            ),
            (string) $validated['intent_signature'],
        );

        if ($sign($sourceTxHash)) {
            return true;
        }

        $reference = $validated['reservation'] ?? null;

        if (! is_string($reference) || $reference === '') {
            return false;
        }

        $reservation = BridgeReservation::where('reference', $reference)->first();

        return $reservation !== null
            && $reservation->bridge_request_id === null
            && $reservation->direction === $validated['direction']
            && $reservation->token === $token
            && $reservation->sender_address !== null
            && BridgeIntent::normalize($reservation->sender_address) === BridgeIntent::normalize((string) $validated['sender_address'])
            && BridgeIntent::normalize($reservation->recipient_address) === BridgeIntent::normalize((string) $validated['recipient_address'])
            && $sign($reference);
    }

    private function existingSubmission(string $sourceChain, string $sourceTxHash): ?BridgeRequest
    {
        return BridgeRequest::query()
            ->where('submitted_source_tx_hash', $sourceTxHash)
            ->orWhere(fn ($query) => $query
                ->when(config("bridge.chains.{$sourceChain}.type") === 'evm',
                    fn ($query) => $query->whereRaw('LOWER(source_tx_hash) = ?', [$sourceTxHash]),
                    fn ($query) => $query->where('source_tx_hash', $sourceTxHash)))
            ->first();
    }

    private function repeatSubmission(BridgeRequest $bridgeRequest, string $fingerprint): JsonResponse
    {
        if (! BridgeSubmission::matches($bridgeRequest, $fingerprint)) {
            return response()->json([
                'message' => 'This source transaction is already registered with different transfer details. Continue the original request.',
                'reason' => 'source_transaction_conflict',
            ], 409);
        }

        return $this->submissionResponse($bridgeRequest, true);
    }

    private function submissionResponse(BridgeRequest $bridgeRequest, bool $replayed, ?int $status = null, ?string $reason = null): JsonResponse
    {
        return response()->json(array_filter([
            'message' => match (true) {
                $bridgeRequest->isCompleted() => 'Bridge completed',
                $bridgeRequest->isAwaitingRecipient() => 'Deposit recorded. It will be paid out once the sending wallet signs the payout authorization for it.',
                default => 'Bridge request submitted',
            },
            'reason' => $reason ?? ($bridgeRequest->isAwaitingRecipient() ? 'recipient_unproven' : null),
            'replayed' => $replayed,
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
        ], fn ($value) => $value !== null), $status ?? ($replayed ? 200 : 201));
    }

    /**
     * Phase 1 of a one-time-address deposit route: commit the recipient and
     * hand back an address that belongs to this request alone. Binding a
     * deposit to a single request with a pre-committed recipient is what
     * prevents hijacking a public deposit transaction — and on Monero it is
     * the only thing that makes a deposit attributable at all, since nobody
     * outside the receiving wallet can see one.
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

        $watcher = app(BridgeDepositWatcher::class);

        if (! $watcher->supports($sourceChain)) {
            return response()->json([
                'message' => 'This route does not use a prepared deposit address.',
            ], 422);
        }

        $routeTokens = $this->bridgeConfig->tokensForRoute($validated['direction']);
        // The route's own first token, not a hardcoded one: this endpoint
        // serves every address-bound corridor now, and each has its own asset.
        $token = $validated['token'] ?? (string) array_key_first($routeTokens);

        if (! isset($routeTokens[$token])) {
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

        // Give it its address: derived from a seed on Yenten, minted by the
        // wallet on Monero. A refusal here is a real reason a person can act
        // on, not a blank field — and the row is closed rather than left
        // waiting for a deposit to an address that does not exist.
        $failure = $watcher->issue($bridgeRequest, $sourceChain);

        if ($failure !== null) {
            $bridgeRequest->markExpired();

            return response()->json(['message' => $failure, 'retryable' => true], 422);
        }

        $bridgeRequest->refresh();

        return response()->json([
            'bridge_request' => [
                'id' => $bridgeRequest->id,
                'direction' => $bridgeRequest->direction,
                'token' => $bridgeRequest->token,
                'deposit_address' => $bridgeRequest->deposit_address,
                'recipient_address' => $bridgeRequest->recipient_address,
                'status' => $bridgeRequest->status,
                'confirmations' => max(1, (int) ($sourceChain['minimum_confirmations'] ?? 1)),
                'expires_at' => $bridgeRequest->created_at
                    ->addMinutes($watcher->depositTtlMinutes($sourceChain))
                    ->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Phase 2: the user has sent (any amount of) coins to the request's
     * one-time address. Detect what landed on it and mint exactly that much to
     * the committed recipient — no amount and no tx hash from the user.
     *
     * The same button on a corridor whose deposits are also swept in the
     * background (`bridge:sweep-deposits`): a person who is watching the page
     * gets an answer now, and a person who closed the tab gets the same one a
     * minute later. Both go through BridgeDepositCredit, so they cannot disagree.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'integer'],
            'session_id' => ['nullable', 'uuid'],
        ]);

        $bridgeRequest = BridgeRequest::find($validated['id']);

        // Expired requests answer without touching any network: the whole
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

        $outcome = app(BridgeDepositCredit::class)->credit(
            $bridgeRequest,
            $validated['session_id'] ?? null,
        );

        if ($outcome->message !== null) {
            return response()->json(array_filter([
                'message' => $outcome->message,
                'retryable' => $outcome->retryable ?: null,
                'expired' => $outcome->expired ?: null,
            ]), 422);
        }

        $bridgeRequest->refresh();

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
            // Address-bound corridors (Monero, Yenten) never have a source
            // hash: the deposit is identified by the address it landed on, and
            // on Monero nobody outside this wallet could look one up anyway.
            // The tracker shows this instead of an explorer link to nowhere.
            'deposit_address' => $bridgeRequest->deposit_address,
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
