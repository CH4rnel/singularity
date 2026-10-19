<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\RecoverEvmAddress;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletChatKey;
use App\Models\WalletChatMessage;
use App\Services\Wallet\ChatMessageNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The relay behind the wallet's chat.
 *
 * Two kinds of message pass through it, and the difference is the sender's
 * choice, made visibly in the composer.
 *
 * **Sealed** messages it cannot read. Their body is AES-GCM ciphertext under
 * a key derived from an ECDH between two wallets, and neither half of that
 * exchange has ever been on this server. There is no decryption path here to
 * disable later and no key escrow: that privacy is structural, not promised.
 *
 * **Plain** messages it can. Sealing needs a published key at both ends, and
 * most of the site's people have never opened the wallet's chat — so until
 * this existed, most of them could not be written to at all. A plain message
 * needs only an address; the sender is told, on the message itself, that
 * Cyberia can read it.
 *
 * What it does with either is four things.
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
 * **A doorbell.** A message the recipient never hears about is one they never
 * read, so each new one tells the address — bell, push, Telegram — that
 * something arrived, without saying who sent it or what it says
 * (`ChatMessageNotifier`).
 *
 * The honest limit, stated here because the UI states it too: the relay sees
 * who is talking to whom and when. Content is sealed, metadata is not.
 */
class WalletChatController extends Controller
{
    /** How long a challenge stays signable. */
    private const NONCE_TTL_SECONDS = 300;

    /**
     * Session key holding every address that proved itself, and when.
     *
     * A map and not one slot, because one browser holds one session and a
     * wallet can hold several accounts — two tabs on two accounts used to
     * overwrite each other's proof on every beat, and each was handed whichever
     * mailbox had been proved last.
     */
    private const SESSION_KEY = 'wallet_chat_proofs';

    /** Where a single proof used to live, read for sessions that still hold one. */
    private const LEGACY_SESSION_KEY = 'wallet_chat_address';

    /** People in one answer of the directory. */
    private const PEOPLE_LIMIT = 60;

    /** Long enough to absorb a screen opening, short enough to feel current. */
    private const PEOPLE_TTL_SECONDS = 60;

    public function __construct(
        private RecoverEvmAddress $recover,
        private ChatMessageNotifier $notifier,
    ) {}

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

        // Moved to the end, so the newest proof is also the last one.
        $proofs = $this->freshProofs($request);
        unset($proofs[$address]);
        $proofs[$address] = now()->timestamp;

        $request->session()->put(self::SESSION_KEY, $proofs);

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

    /**
     * Who there is to write to.
     *
     * A chat addressed by EVM address is a chat you can only start if you
     * already know the address, which in practice meant copying forty hex
     * characters out of a feed post — the screen had a text field and no answer
     * to "who is here". This is that answer, and it is deliberately the *site's
     * people*: accounts that have attached a wallet, whose name and address are
     * already on a public profile page. Nothing new is disclosed; what is new
     * is that it can be read as a list.
     *
     * A row says whether that address has ever published a messaging key,
     * because without one nothing can be encrypted to it. Saying so here is
     * what lets the screen show somebody and explain why they cannot be written
     * to yet, rather than offering a composer that could only fail.
     *
     * Public and cached like the rest of the directory: the key records are
     * already served one by one, and a session would buy nothing.
     */
    public function people(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:64'],
            // The correspondents already in a wallet's list, so a thread can
            // carry a name and a face instead of twelve characters of hex.
            'addresses' => ['sometimes', 'nullable', 'string', 'max:'.(43 * self::PEOPLE_LIMIT)],
        ]);

        $query = trim((string) ($data['q'] ?? ''));
        $addresses = collect(explode(',', Str::lower((string) ($data['addresses'] ?? ''))))
            ->map(fn (string $address): string => trim($address))
            ->filter(fn (string $address): bool => (bool) preg_match('/^0x[0-9a-f]{40}$/', $address))
            ->unique()
            ->sort()
            ->take(self::PEOPLE_LIMIT)
            ->values()
            ->all();

        $cacheKey = 'wallet.chat.people.'.md5(Str::lower($query).'|'.implode(',', $addresses));

        return response()->json(Cache::remember(
            $cacheKey,
            self::PEOPLE_TTL_SECONDS,
            fn (): array => ['people' => $this->peopleFor($query, $addresses)],
        ));
    }

    /**
     * @param  list<string>  $addresses
     * @return list<array{name: string, address: string, url: string|null, avatar: string|null, hasKey: bool}>
     */
    private function peopleFor(string $query, array $addresses = []): array
    {
        $users = User::query()
            ->whereNull('merged_into_id')
            ->whereNotNull('wallet_address')
            ->when($addresses !== [], fn ($builder) => $builder
                ->whereIn(DB::raw('lower(wallet_address)'), $addresses))
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.Str::lower($query).'%';

                $builder->where(function ($inner) use ($like): void {
                    $inner->whereRaw('lower(name) like ?', [$like])
                        ->orWhereRaw('lower(onchain_nickname) like ?', [$like])
                        ->orWhereRaw('lower(wallet_address) like ?', [$like]);
                });
            })
            ->orderByDesc('id')
            ->limit(self::PEOPLE_LIMIT)
            ->get(['id', 'name', 'onchain_nickname', 'avatar_path', 'wallet_address']);

        $addresses = $users
            ->map(fn (User $user): string => Str::lower((string) $user->wallet_address))
            ->all();

        $withKeys = WalletChatKey::query()
            ->whereIn('address', $addresses)
            ->pluck('address')
            ->all();

        return $users
            ->map(fn (User $user): array => [
                'name' => $user->name,
                'address' => Str::lower((string) $user->wallet_address),
                'url' => $user->profile_url,
                'avatar' => $user->avatar,
                'hasKey' => in_array(
                    Str::lower((string) $user->wallet_address),
                    $withKeys,
                    true,
                ),
            ])
            // Somebody who can be written to right now comes first; the rest
            // are still listed, because "they have not opened chat" is the
            // answer to why they are not.
            ->sortByDesc('hasKey')
            ->values()
            ->all();
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
            // the session's proofs rather than trusted.
            'from' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $from = $this->provenAddress($request, $data['from'] ?? null);

        if ($from === null) {
            return response()->json([
                'message' => 'Sign the challenge with your wallet to send messages.',
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

        $this->ringDoorbell($from, $to);

        return response()->json(['message' => $this->messagePayload($record)], 201);
    }

    /**
     * Accept one message that is not encrypted.
     *
     * The sender is still the session's proven address, for the same reason as
     * above: a relay that took anybody's word for who wrote something would
     * let anyone put words in anyone's mouth — and here the words are legible.
     *
     * Unlike a sealed envelope, the recipient needs no published key. That is
     * the whole point: somebody who has never opened the wallet's chat can
     * still be written to, find the message waiting and be told it is there.
     */
    public function sendPlain(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
            'to' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'sentAt' => ['required', 'string', 'max:40'],
            'text' => ['required', 'string', 'max:'.config('wallet.chat.max_text_chars')],
            'from' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $from = $this->provenAddress($request, $data['from'] ?? null);

        if ($from === null) {
            return response()->json([
                'message' => 'Sign the challenge with your wallet to send messages.',
            ], 403);
        }

        if ($this->parseTime($data['sentAt']) === null) {
            return response()->json(['message' => 'That timestamp is not a date.'], 422);
        }

        $text = trim($data['text']);

        if ($text === '') {
            return response()->json(['message' => 'That message is empty.'], 422);
        }

        $existing = WalletChatMessage::query()->where('message_id', $data['id'])->first();

        if ($existing !== null) {
            // A retry, answered with what is stored. An id that already names
            // somebody else's message is not this sender's to reuse.
            if ($existing->from_address !== $from) {
                return response()->json(['message' => 'That message id is taken.'], 409);
            }

            return response()->json(['message' => $this->messagePayload($existing)]);
        }

        $record = WalletChatMessage::query()->create([
            'message_id' => $data['id'],
            'from_address' => $from,
            'to_address' => Str::lower($data['to']),
            'sealed' => false,
            'sent_at' => $data['sentAt'],
            'iv' => null,
            'body' => $text,
        ]);

        $this->ringDoorbell($from, $record->to_address);

        return response()->json(['message' => $this->messagePayload($record)], 201);
    }

    /**
     * Everything in this mailbox after a cursor — both directions.
     *
     * Sent messages come back too, and that is not redundancy: the sender can
     * decrypt them (the conversation key is shared), so a wallet restored on a
     * second device recovers its own half of the conversation without the
     * relay ever holding a readable copy.
     *
     * `address` names the mailbox, and must be one this session proved; the
     * answer names it back, so a wallet never files one account's mail under
     * another. Without it, the most recently proved mailbox is read — what a
     * wallet that predates the parameter still expects.
     */
    public function messages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'since' => ['sometimes', 'integer', 'min:0'],
            'address' => ['sometimes', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
        ]);

        $address = $this->provenAddress($request, $data['address'] ?? null);

        if ($address === null) {
            return response()->json([
                'message' => 'Sign the challenge with your wallet to read messages.',
            ], 403);
        }

        $rows = WalletChatMessage::query()
            ->where('id', '>', (int) ($data['since'] ?? 0))
            ->where(fn ($query) => $query
                ->where('to_address', $address)
                ->orWhere('from_address', $address))
            ->orderBy('id')
            ->limit((int) config('wallet.chat.page'))
            ->get();

        return response()->json([
            'address' => $address,
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
        $payload = [
            'seq' => (int) $record->id,
            'id' => $record->message_id,
            'from' => $record->from_address,
            'to' => $record->to_address,
            'sentAt' => $record->sent_at,
        ];

        // A plain message travels as `text`, never as `body`: a client that
        // has only ever seen ciphertext in `body` must not be handed words
        // there and try to open them.
        if (! $record->sealed) {
            return [...$payload, 'plain' => true, 'text' => $record->body];
        }

        return [...$payload, 'iv' => $record->iv, 'body' => $record->body];
    }

    /**
     * Tell the recipient after the response has gone: a slow push service is
     * the sender's problem only if it delays their message, and it must not.
     */
    private function ringDoorbell(string $from, string $to): void
    {
        $notifier = $this->notifier;

        dispatch(fn () => $notifier->notify($from, $to))->afterResponse();
    }

    /**
     * The address a request may act as: the one it names, if this session
     * proved it and the proof is still fresh — or, when it names none, the one
     * proved most recently.
     */
    private function provenAddress(Request $request, ?string $wanted = null): ?string
    {
        $proofs = $this->freshProofs($request);

        if ($wanted !== null) {
            $wanted = Str::lower($wanted);

            return array_key_exists($wanted, $proofs) ? $wanted : null;
        }

        // The newest proof is the last one written (`verify` moves it there).
        $latest = array_key_last($proofs);

        return $latest === null ? null : (string) $latest;
    }

    /**
     * Every proof this session holds that has not aged out, address → when.
     *
     * @return array<string, int>
     */
    private function freshProofs(Request $request): array
    {
        $session = $request->session();
        $proofs = $session->get(self::SESSION_KEY);
        $proofs = is_array($proofs) ? $proofs : [];

        $legacy = $session->pull(self::LEGACY_SESSION_KEY);

        if (is_array($legacy) && is_string($legacy['address'] ?? null)) {
            $proofs[$legacy['address']] = (int) ($legacy['proved_at'] ?? 0);
        }

        $oldest = now()->timestamp - (int) config('wallet.chat.proof_minutes') * 60;

        $fresh = array_filter(
            $proofs,
            fn ($at, $address): bool => is_string($address) && is_int($at) && $at >= $oldest,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($fresh !== $proofs) {
            $session->put(self::SESSION_KEY, $fresh);
        }

        return $fresh;
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
     *
     * Must stay byte-identical to `chatChallenge` in
     * resources/js/lib/wallet/chatCrypto.ts. The wallet signs this without
     * asking — it is what lets it check for mail in the background — and it
     * only does so for text that matches its own copy exactly, so a server
     * that sent anything else would get no signature at all.
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
