<?php

namespace App\Http\Controllers\Api\Ai;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Exceptions\AiApiException;
use App\Http\Controllers\Controller;
use App\Models\AiApiKey;
use App\Services\Ai\AiHolderGate;
use App\Services\Ai\AiKeyService;
use App\Services\Ai\AiUsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Self-service keys for the inference API.
 *
 * There is no signup and no account: the wallet signs a one-shot challenge and
 * the address recovered from that signature owns the key. It is the same proof
 * the $LAIN holders' room uses, worded differently on purpose — a signature
 * made here can be replayed neither at /api/wallet/verify nor at the room.
 *
 * Issuing needs the holding; listing and revoking do not. Someone who sold
 * their position must still be able to see and kill the keys they left behind,
 * and a gate that locked them out of their own credentials would be a gate
 * that protects nobody.
 */
class AiKeyController extends Controller
{
    public function __construct(
        private AiKeyService $keys,
        private AiHolderGate $gate,
        private AiUsageMeter $meter,
        private RecoverEvmAddress $recover,
    ) {}

    /** POST /api/ai/keys/nonce — a challenge for one address. */
    public function nonce(Request $request): JsonResponse
    {
        $address = Str::lower($request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ])['address']);

        $nonce = Str::random(40);
        $ttl = (int) config('ai.challenge_ttl_seconds', 300);

        Cache::put($this->nonceKey($address), $nonce, $ttl);

        return response()->json([
            'message' => $this->challenge($address, $nonce),
            'expires_in' => $ttl,
        ]);
    }

    /** POST /api/ai/keys — issue one. The plaintext appears here and nowhere else. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string'],
            'name' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $address = $this->prove($data['address'], $data['signature']);
        $status = $this->gate->assert($address);

        ['key' => $key, 'token' => $token] = $this->keys->issue($address, $data['name'] ?? null);

        return response()->json([
            'key' => $token,
            'warning' => 'This is the only time the key is shown. Store it somewhere safe.',
            'record' => $key->toPublicArray(),
            'gate' => $this->gateView($status),
        ], 201);
    }

    /** POST /api/ai/keys/list — this address's keys, secrets excluded. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string'],
        ]);

        $address = $this->prove($data['address'], $data['signature']);

        return response()->json([
            'address' => $address,
            'keys' => $this->keys->forAddress($address)
                ->map(fn (AiApiKey $key): array => $key->toPublicArray())
                ->all(),
        ]);
    }

    /** POST /api/ai/keys/revoke — kill one, permanently. */
    public function revoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string'],
            'id' => ['required', 'integer'],
        ]);

        $address = $this->prove($data['address'], $data['signature']);

        $key = AiApiKey::where('id', $data['id'])->where('address', $address)->first();

        if ($key === null) {
            throw AiApiException::invalidRequest('No such key for this address.', 'id', 'key_not_found');
        }

        return response()->json(['record' => $this->keys->revoke($key)->toPublicArray()]);
    }

    /**
     * GET /api/ai/v1/me — what the presented key is and what it has left.
     *
     * The one endpoint that answers with a key rather than a signature, since
     * it is about the key: whether it still passes the gate, and how much of
     * today's quota it has spent.
     */
    public function status(Request $request): JsonResponse
    {
        $key = $request->attributes->get('ai_api_key');
        $gate = $request->attributes->get('ai_gate_status');

        if (! $key instanceof AiApiKey) {
            throw AiApiException::unauthorized('This endpoint requires an API key.');
        }

        return response()->json([
            'address' => $key->address,
            'key' => $key->toPublicArray(),
            // A service key never had a gate reading to report, and asking the
            // chain about an address that was never required to hold anything
            // would only produce a confusing "0%".
            'gate' => is_array($gate) ? $this->gateView($gate) : ['exempt' => true] + $this->gate->terms(),
            'usage' => $this->meter->summary($key),
        ]);
    }

    /**
     * The address behind a signature, or the end of the request.
     *
     * The nonce is pulled rather than read: a challenge answers exactly once,
     * so a captured signature is worth nothing a second time.
     */
    private function prove(string $address, string $signature): string
    {
        $address = Str::lower($address);
        $nonce = Cache::pull($this->nonceKey($address));

        if (! is_string($nonce)) {
            throw AiApiException::invalidRequest(
                'That challenge expired or was already used. Ask for a new one.',
                'signature',
                'challenge_expired',
            );
        }

        $signer = $this->recover->handle($this->challenge($address, $nonce), $signature);

        if ($signer === null || Str::lower($signer) !== $address) {
            throw AiApiException::unauthorized(
                'That signature was not made by this address.',
                'invalid_signature',
            );
        }

        return $address;
    }

    private function nonceKey(string $address): string
    {
        return "ai-api-nonce:{$address}";
    }

    /**
     * The text the wallet signs.
     *
     * It names this API and this address, so it cannot be presented as a login
     * or as another wallet's proof.
     */
    private function challenge(string $address, string $nonce): string
    {
        return implode("\n", [
            'Cyberia AI API — manage the inference keys for this address.',
            'This signature proves the address below. It moves no funds and approves no transaction.',
            "Address: {$address}",
            "Nonce: {$nonce}",
        ]);
    }

    /** @param  array<string, mixed>  $status */
    private function gateView(array $status): array
    {
        return [
            'token' => $status['token'],
            'symbol' => $status['symbol'],
            'balance' => $status['balance'],
            'minimum_balance' => $status['minimum_balance'],
            'share' => $this->gate->percent((int) $status['share_bps']),
            'minimum_share' => $this->gate->percent((int) $status['minimum_share_bps']),
            'qualifies' => (bool) $status['qualifies'],
        ];
    }
}
