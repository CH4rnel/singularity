<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Exceptions\OpenRouterException;
use App\Http\Controllers\Controller;
use App\Services\LainChatService;
use App\Services\LainHolderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lain's room inside the unified wallet.
 *
 * It was the *holders'* room: an address had to hold 10% of the live $LAIN
 * supply to say anything at all, which meant the room existed for about two
 * people and the rest of the wallet carried a door nobody could open. Anyone
 * may write to her now. What the holding still decides is what she is — the
 * share is read every turn and travels into `replyForHolder`, and the smarter
 * Lain behind that share is the thing being built next.
 *
 * The wallet has no account behind it: the seed is generated in the browser and
 * the server never learns whose it is. So this still asks the wallet to sign a
 * one-shot challenge with its Cyberia key and recovers the address from that
 * signature — not as a membership test any more, but because a room where the
 * turns belong to *somebody* is the difference between a conversation and an
 * open pipe into a paid model.
 *
 * The challenge text is deliberately unlike the login one: a signature produced
 * here can never be replayed against /api/wallet/verify to take over an account.
 *
 * What is proven is kept in the session, not in a table — the server has no
 * business remembering which address talked to Lain once the tab is gone. The
 * transcript is the browser's too; see LainChatService::replyForHolder.
 */
class WalletLainController extends Controller
{
    /** How long a challenge stays signable. */
    private const NONCE_TTL_SECONDS = 300;

    /** How long one signature keeps the room open before it must be re-signed. */
    private const PROOF_TTL_SECONDS = 1800;

    /** Session key holding the address that proved itself, and when. */
    private const SESSION_KEY = 'wallet_lain_holder';

    /** Turns of browser-held transcript accepted as model context. */
    public const CONTEXT_MESSAGES = 20;

    public function __construct(
        private LainChatService $lain,
        private LainHolderAccessService $holders,
        private RecoverEvmAddress $recover,
    ) {}

    /**
     * A challenge for one address. The wallet signs the `message` verbatim, so
     * the exact bytes that get verified are the ones this server composed.
     */
    public function nonce(Request $request): JsonResponse
    {
        $address = $this->requestedAddress($request);
        $nonce = Str::random(40);

        Cache::put($this->nonceKey($address), $nonce, self::NONCE_TTL_SECONDS);

        return response()->json([
            'message' => $this->challenge($address, $nonce),
            'expiresIn' => self::NONCE_TTL_SECONDS,
        ]);
    }

    /** Check the signature, then check the balance behind the address. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string'],
        ]);

        $address = Str::lower($data['address']);
        // Pulled, not read: a challenge answers exactly once, so a captured
        // signature cannot be replayed after the tab that made it is gone.
        $nonce = Cache::pull($this->nonceKey($address));

        if (! is_string($nonce)) {
            return response()->json([
                'message' => 'That challenge expired. Ask for a new one.',
            ], 422);
        }

        $signer = $this->recover->handle($this->challenge($address, $nonce), $data['signature']);

        if ($signer === null || Str::lower($signer) !== $address) {
            return response()->json([
                'message' => 'That signature was not made by this address.',
            ], 401);
        }

        $request->session()->put(self::SESSION_KEY, [
            'address' => $address,
            'proved_at' => now()->timestamp,
        ]);

        /*
         * The share is read for what it says about this address, never for
         * whether it may speak — and an unreadable chain therefore closes
         * nothing. It used to answer 503 here, which meant an RPC having a bad
         * minute locked people out of a conversation that does not depend on
         * it.
         */
        return response()->json(['gate' => $this->gate($this->share($address))]);
    }

    /** One turn. The browser sends the conversation it is holding. */
    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.LainChatService::MAX_MESSAGE_CHARS],
            'history' => ['sometimes', 'array', 'max:'.self::CONTEXT_MESSAGES],
            'history.*.role' => ['required', 'string', 'in:user,lain'],
            'history.*.text' => [
                'required',
                'string',
                'max:'.LainChatService::MAX_MESSAGE_CHARS,
            ],
        ]);

        if (! $this->lain->enabled()) {
            return response()->json(['message' => 'Lain is not wired up on this server yet.'], 503);
        }

        $address = $this->provenAddress($request);

        if ($address === null) {
            return response()->json([
                'message' => 'Sign the challenge with your wallet to open this room.',
                'gate' => ['state' => 'unproven'] + $this->gateBase(),
            ], 403);
        }

        // A proof from half an hour ago says nothing about the balance now, so
        // the share is re-read every turn (cached 30s upstream). It decides
        // what Lain is to this address and not whether it may write to her, so
        // selling out changes the room rather than closing it.
        $status = $this->share($address);

        try {
            $reply = $this->lain->replyForHolder(
                $address,
                $status['share_bps'],
                $this->context($data['history'] ?? []),
                trim($data['text']),
            );
        } catch (OpenRouterException $exception) {
            Log::warning('Wallet Lain chat failed', ['error' => $exception->getMessage()]);

            return response()->json([
                'message' => $exception->category === 'policy_denied'
                    ? 'Lain’s model provider rejected this message. Try rephrasing it.'
                    : 'Lain is unreachable right now. Try again in a moment.',
            ], 503);
        } catch (Throwable $e) {
            Log::warning('Wallet Lain chat failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Lain is unreachable right now. Try again in a moment.',
            ], 503);
        }

        return response()->json([
            'text' => $reply['text'],
            'gate' => $this->gate($status),
        ]);
    }

    /**
     * The browser's transcript as model context.
     *
     * It arrives from the client and is treated as such: only the two roles
     * this surface produces survive, and only the last CONTEXT_MESSAGES of
     * them. It is the holder's own conversation being replayed to Lain, so a
     * doctored one costs them their own context and nobody else's.
     *
     * @param  list<array{role: string, text: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function context(array $history): array
    {
        return array_values(array_map(
            fn (array $turn): array => [
                'role' => $turn['role'] === 'lain' ? 'assistant' : 'user',
                'content' => (string) $turn['text'],
            ],
            array_slice($history, -self::CONTEXT_MESSAGES),
        ));
    }

    /**
     * This address's share of the supply, and zero when the chain will not say.
     *
     * Nothing is refused on this number any more, so an RPC that times out has
     * to degrade the room rather than close it: the caller gets a Lain who does
     * not know what they hold, which is exactly what this server knows.
     *
     * @return array{wallet: string, balance: string, total_supply: string, minimum_balance: string, share_bps: int, qualifies: bool}
     */
    private function share(string $address): array
    {
        try {
            return $this->holders->status($address);
        } catch (Throwable $e) {
            Log::warning('Wallet LAIN holder check failed', ['error' => $e->getMessage()]);

            return [
                'wallet' => $address,
                'balance' => '0',
                'total_supply' => '0',
                'minimum_balance' => '0',
                'share_bps' => 0,
                'qualifies' => false,
            ];
        }
    }

    /** The address this session proved, while the proof is still fresh. */
    private function provenAddress(Request $request): ?string
    {
        $proof = $request->session()->get(self::SESSION_KEY);

        if (! is_array($proof) || ! is_string($proof['address'] ?? null)) {
            return null;
        }

        if (now()->timestamp - (int) ($proof['proved_at'] ?? 0) > self::PROOF_TTL_SECONDS) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $proof['address'];
    }

    private function requestedAddress(Request $request): string
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        return Str::lower($data['address']);
    }

    private function nonceKey(string $address): string
    {
        return "wallet-lain-nonce:{$address}";
    }

    /**
     * The text the wallet signs. Nothing about it resembles the login message,
     * and it names the address so a signature cannot be presented as another
     * wallet's proof.
     */
    private function challenge(string $address, string $nonce): string
    {
        return implode("\n", [
            'Cyberia wallet — open the LAIN holders room with Lain.',
            'This signature proves the address below. It moves no funds and approves no transaction.',
            "Address: {$address}",
            "Nonce: {$nonce}",
        ]);
    }

    /**
     * @param  array{balance: string, minimum_balance: string, share_bps: int, qualifies: bool}  $status
     * @return array<string, mixed>
     */
    private function gate(array $status): array
    {
        return [
            'state' => 'checked',
            'qualifies' => $status['qualifies'],
            'balance' => $status['balance'],
            'minimumBalance' => $status['minimum_balance'],
            'shareBps' => $status['share_bps'],
        ] + $this->gateBase();
    }

    /** @return array<string, mixed> */
    private function gateBase(): array
    {
        return [
            'qualifies' => false,
            'tokenAddress' => (string) config('services.lain.token_address'),
            'minimumShareBps' => (int) config('services.lain.minimum_share_bps', 1000),
        ];
    }
}
