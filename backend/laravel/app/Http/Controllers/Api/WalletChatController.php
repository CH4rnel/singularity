<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Http\Controllers\Controller;
use App\Models\WalletChatKey;
use App\Models\WalletChatMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The relay behind the wallet's end-to-end encrypted chat.
 *
 * This controller carries messages it cannot read. Every body that passes
 * through it is AES-GCM ciphertext under a key derived from an ECDH between
 * two wallets, and neither half of that exchange has ever been on this server.
 * There is no decryption path here to disable later, no key escrow, and no
 * plaintext column: the privacy is structural rather than promised.
 *
 * What it does do is three things.
 *
 * **A directory.** An EVM address is a hash of a public key, so it cannot be
 * encrypted to on its own. Addresses publish a separate messaging key, signed
 * with the address's own key over a statement naming both. That signature is
 * stored and served with every lookup, and the browser re-checks it — which is
 * what stops this server from handing out a key of its own and reading
 * everything. The check here is a duplicate of the client's, kept because a
 * directory of unverifiable records is not worth storing.
 *
 * **A mailbox.** Reading one means proving the address, the same way the
 * holders' room does: sign a one-shot challenge, and the recovered address is
 * kept in the session for half an hour. The wording of the challenge matches
 * nothing else on this site, so a signature made here cannot be replayed at
 * /api/wallet/verify to take over an account.
 *
 * **A queue, not an archive.** Envelopes are deleted after the retention
 * window (`wallet:chat-prune`); the wallets at either end hold the
 * conversation.
 *
 * The honest limit, stated here because the UI states it too: the relay sees
 * who is talking to whom and when. Content is sealed, metadata is not.
 */
class WalletChatController extends Controller
{
    /** How long a challenge stays signable. */
    private const NONCE_TTL_SECONDS = 300;

    /** Session key holding the address that proved itself, and when. */
    private const SESSION_KEY = 'wallet_chat_address';

    public function __construct(private RecoverEvmAddress $recover) {}

    /* ------------------------------------------------------------ mailbox --- */

    /**
     * A challenge for one address. The wallet signs the `message` verbatim, so
     * the bytes that get verified are the ones this server composed.
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

    /** Check the signature and open this address's mailbox for the session. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string', 'max:200'],
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

        return response()->json(['address' => $address]);
    }

    /* ---------------------------------------------------------- directory --- */

    /**
     * Publish a messaging key.
     *
     * Deliberately not session-gated. The record carries a signature over a
     * statement naming the address, which proves authorship better than a
     * cookie does — and requiring both would mean a wallet had to prove itself
     * twice to say one public thing about itself.
     *
     * A key is replaced only by a strictly newer one, so a replayed old record
     * cannot roll someone back to a key they have rotated away from.
     */
    public function publishKey(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'publicKey' => ['required', 'string', 'regex:/^0x0[23][0-9a-fA-F]{64}$/'],
            'issuedAt' => ['required', 'string', 'max:40'],
            'signature' => ['required', 'string', 'max:200'],
        ]);

        $address = Str::lower($data['address']);
        $publicKey = Str::lower($data['publicKey']);
        $issuedAt = $data['issuedAt'];

        $issued = $this->parseTime($issuedAt);

        if ($issued === null || $issued->isAfter(now()->addMinutes(5))) {
            return response()->json([
                'message' => 'That key is dated in the future.',
            ], 422);
        }

        $signer = $this->recover->handle(
            $this->keyStatement($address, $publicKey, $issuedAt),
            $data['signature'],
        );

        if ($signer === null || Str::lower($signer) !== $address) {
            return response()->json([
                'message' => 'That signature was not made by this address.',
            ], 401);
        }

        $existing = WalletChatKey::query()->where('address', $address)->first();

        if ($existing !== null) {
            $current = $this->parseTime($existing->issued_at);

            if ($current !== null && ! $issued->isAfter($current)) {
                // Not an error: a wallet republishes the same key every time it
                // opens chat, and the stored record is already this one.
                return response()->json($this->keyPayload($existing));
            }
        }

        $record = WalletChatKey::query()->updateOrCreate(
            ['address' => $address],
            [
                'public_key' => $publicKey,
                'issued_at' => $issuedAt,
                'signature' => $data['signature'],
            ],
        );

        return response()->json($this->keyPayload($record), $existing === null ? 201 : 200);
    }

    /**
     * The key an address published, if it has ever opened chat.
     *
     * Public, because a conversation cannot start without it. What it reveals
     * is that an address uses this chat at all — which is why publishing is an
     * act the user takes, not something the wallet does on their behalf.
     */
    public function key(string $address): JsonResponse
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return response()->json(['message' => 'Not an EVM address.'], 422);
        }

        $record = WalletChatKey::query()->where('address', Str::lower($address))->first();

        if ($record === null) {
            return response()->json([
                'message' => 'This address has not opened encrypted chat yet.',
            ], 404);
        }

        return response()->json($this->keyPayload($record));
    }

    /* ----------------------------------------------------------- messages --- */

    /**
     * Accept one sealed envelope.
     *
     * The sender is the session's proven address rather than anything in the
     * body: a relay that took the sender's word for who they are would let
     * anyone put messages in anyone's thread. The recipient must have a
     * published key — without one nothing could have been encrypted to them,
     * so an envelope addressed there is either a mistake or an attempt to use
     * the queue as storage.
     */
    public function send(Request $request): JsonResponse
    {
        $from = $this->provenAddress($request);

        if ($from === null) {
            return response()->json([
                'message' => 'Sign the challenge with your wallet to send messages.',
            ], 403);
        }

        $data = $request->validate([
            'id' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
            'to' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'sentAt' => ['required', 'string', 'max:40'],
            'iv' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9+\/]+={0,2}$/'],
            'body' => [
                'required',
                'string',
                'max:'.config('wallet.chat.max_body_chars'),
                'regex:/^[A-Za-z0-9+\/]+={0,2}$/',
            ],
            // Present in the request because the tag covers it; checked against
            // the session rather than trusted.
            'from' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        if (isset($data['from']) && Str::lower($data['from']) !== $from) {
            return response()->json([
                'message' => 'That message claims a different sender than this wallet proved.',
            ], 403);
        }

        if ($this->parseTime($data['sentAt']) === null) {
            return response()->json(['message' => 'That timestamp is not a date.'], 422);
        }

        $to = Str::lower($data['to']);

        if (! WalletChatKey::query()->where('address', $to)->exists()) {
            return response()->json([
                'message' => 'That address has not opened encrypted chat yet.',
            ], 422);
        }

        $existing = WalletChatMessage::query()->where('message_id', $data['id'])->first();

        if ($existing !== null) {
            // A retried send is the same message, not a second one. Answering
            // with the stored row keeps a flaky connection from duplicating a
            // thread — and the id is inside the tag, so it cannot be reused
            // for different content.
            return response()->json(['message' => $this->messagePayload($existing)]);
        }

        $record = WalletChatMessage::query()->create([
            'message_id' => $data['id'],
            'from_address' => $from,
            'to_address' => $to,
            'sent_at' => $data['sentAt'],
            'iv' => $data['iv'],
            'body' => $data['body'],
        ]);

        return response()->json(['message' => $this->messagePayload($record)], 201);
    }

    /**
     * Everything in this mailbox after a cursor — both directions.
     *
     * Sent messages come back too, and that is not redundancy: the sender can
     * decrypt them (the conversation key is shared), so a wallet restored on a
     * second device recovers its own half of the conversation without the
     * relay ever holding a readable copy.
     */
    public function messages(Request $request): JsonResponse
    {
        $address = $this->provenAddress($request);

        if ($address === null) {
            return response()->json([
                'message' => 'Sign the challenge with your wallet to read messages.',
            ], 403);
        }

        $data = $request->validate([
            'since' => ['sometimes', 'integer', 'min:0'],
        ]);

        $rows = WalletChatMessage::query()
            ->where('id', '>', (int) ($data['since'] ?? 0))
            ->where(fn ($query) => $query
                ->where('to_address', $address)
                ->orWhere('from_address', $address))
            ->orderBy('id')
            ->limit((int) config('wallet.chat.page'))
            ->get();

        return response()->json([
            'messages' => $rows->map(fn (WalletChatMessage $row) => $this->messagePayload($row))->all(),
            'cursor' => (int) ($rows->last()?->id ?? $data['since'] ?? 0),
        ]);
    }

    /* ------------------------------------------------------------ helpers --- */

    /** @return array<string, string> */
    private function keyPayload(WalletChatKey $record): array
    {
        return [
            'address' => $record->address,
            'publicKey' => $record->public_key,
            'issuedAt' => $record->issued_at,
            'signature' => $record->signature,
        ];
    }

    /** @return array<string, mixed> */
    private function messagePayload(WalletChatMessage $record): array
    {
        return [
            'seq' => (int) $record->id,
            'id' => $record->message_id,
            'from' => $record->from_address,
            'to' => $record->to_address,
            'sentAt' => $record->sent_at,
            'iv' => $record->iv,
            'body' => $record->body,
        ];
    }

    /** The address this session proved, while the proof is still fresh. */
    private function provenAddress(Request $request): ?string
    {
        $proof = $request->session()->get(self::SESSION_KEY);

        if (! is_array($proof) || ! is_string($proof['address'] ?? null)) {
            return null;
        }

        $age = now()->timestamp - (int) ($proof['proved_at'] ?? 0);

        if ($age > (int) config('wallet.chat.proof_minutes') * 60) {
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
        return "wallet-chat-nonce:{$address}";
    }

    private function parseTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The text a wallet signs to read its mail. Names the address, so a
     * signature cannot be presented as another wallet's proof, and resembles
     * no other message this site asks for.
     */
    private function challenge(string $address, string $nonce): string
    {
        return implode("\n", [
            'Cyberia wallet — open my encrypted chat.',
            'This signature proves the address below so the relay will hand over messages addressed to it. It moves no funds and approves no transaction.',
            "Address: {$address}",
            "Nonce: {$nonce}",
        ]);
    }

    /**
     * The statement that publishes a messaging key.
     *
     * Must stay byte-identical to `chatKeyStatement` in
     * resources/js/lib/wallet/chatCrypto.ts — every wallet in existence
     * verifies published keys against its own copy of these words, so changing
     * one of them here silently invalidates the whole directory.
     */
    private function keyStatement(string $address, string $publicKey, string $issuedAt): string
    {
        return implode("\n", [
            'Cyberia wallet — publish my chat key.',
            'This signature publishes a public key that others use to encrypt messages to this address. It moves no funds and approves no transaction.',
            "Address: {$address}",
            "Chat key: {$publicKey}",
            "Issued: {$issuedAt}",
        ]);
    }
}
