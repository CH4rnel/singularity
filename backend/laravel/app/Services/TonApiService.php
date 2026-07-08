<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TON deposit verification via the tonapi.io REST API — no TON SDK needed
 * server-side (payouts are handled by crypto/ton/scripts/relay-jetton-transfer.ts
 * and relay-ton-transfer.ts).
 */
class TonApiService
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
        /** tonapi may lag a few seconds behind the chain — retry not-found lookups. */
        private readonly int $lookupAttempts = 4,
        private readonly int $lookupDelaySeconds = 5,
    ) {}

    /**
     * Verify a jetton deposit: the event behind $txHash must contain a
     * successful JettonTransfer of $expectedJettonMasterRaw from
     * $expectedSenderRaw to $expectedRecipientRaw. All addresses raw-form
     * ("wc:hex64", see normalizeAddress). Returns the raw jetton amount
     * (smallest units) plus the canonical event id, or null.
     *
     * @return array{amount: string, event_id: string}|null
     */
    public function verifyJettonDeposit(
        string $txHash,
        string $expectedSenderRaw,
        string $expectedJettonMasterRaw,
        string $expectedRecipientRaw,
    ): ?array {
        $event = $this->fetchEvent($txHash);

        if ($event === null) {
            return null;
        }

        foreach ($event['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') !== 'JettonTransfer' || ($action['status'] ?? '') !== 'ok') {
                continue;
            }

            $transfer = $action['JettonTransfer'] ?? [];

            $sender = strtolower((string) ($transfer['sender']['address'] ?? ''));
            $recipient = strtolower((string) ($transfer['recipient']['address'] ?? ''));
            $jetton = strtolower((string) ($transfer['jetton']['address'] ?? ''));
            $amount = (string) ($transfer['amount'] ?? '');

            if ($sender !== $expectedSenderRaw) {
                continue;
            }

            if ($recipient !== $expectedRecipientRaw) {
                continue;
            }

            if ($jetton !== $expectedJettonMasterRaw) {
                continue;
            }

            if ($amount === '' || ! ctype_digit($amount)) {
                continue;
            }

            return ['amount' => $amount, 'event_id' => $this->eventId($event, $txHash)];
        }

        Log::warning('Bridge: no matching JettonTransfer in TON event', [
            'tx' => $txHash,
            'expected_sender' => $expectedSenderRaw,
            'expected_jetton' => $expectedJettonMasterRaw,
        ]);

        return null;
    }

    /**
     * Verify a NATIVE Toncoin deposit: the event behind $txHash must contain a
     * successful TonTransfer from $expectedSenderRaw to $expectedRecipientRaw.
     * Returns the amount in nanotons plus the canonical event id, or null.
     *
     * @return array{amount: string, event_id: string}|null
     */
    public function verifyNativeDeposit(
        string $txHash,
        string $expectedSenderRaw,
        string $expectedRecipientRaw,
    ): ?array {
        $event = $this->fetchEvent($txHash);

        if ($event === null) {
            return null;
        }

        foreach ($event['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') !== 'TonTransfer' || ($action['status'] ?? '') !== 'ok') {
                continue;
            }

            $transfer = $action['TonTransfer'] ?? [];

            $sender = strtolower((string) ($transfer['sender']['address'] ?? ''));
            $recipient = strtolower((string) ($transfer['recipient']['address'] ?? ''));
            // tonapi encodes TonTransfer amounts as JSON integers (nanotons).
            $amount = (string) ($transfer['amount'] ?? '');

            if ($sender !== $expectedSenderRaw) {
                continue;
            }

            if ($recipient !== $expectedRecipientRaw) {
                continue;
            }

            if ($amount === '' || ! ctype_digit($amount)) {
                continue;
            }

            return ['amount' => $amount, 'event_id' => $this->eventId($event, $txHash)];
        }

        Log::warning('Bridge: no matching TonTransfer in TON event', [
            'tx' => $txHash,
            'expected_sender' => $expectedSenderRaw,
            'expected_recipient' => $expectedRecipientRaw,
        ]);

        return null;
    }

    /**
     * Canonical id of an event: the trace id (root transaction hash). Replay
     * protection pins bridge requests to this id, so a deposit submitted under
     * its external-message hash (TON Connect) and under its transaction hash
     * count as the same deposit.
     *
     * @param  array<string, mixed>  $event
     */
    private function eventId(array $event, string $fallback): string
    {
        $id = strtolower((string) ($event['event_id'] ?? ''));

        return preg_match('/^[0-9a-f]{64}$/', $id) ? $id : strtolower($fallback);
    }

    /**
     * Fetch a finished tonapi event by tx/event hash. TON Connect only hands
     * the frontend the signed external message (BOC), so lookups that 404 as
     * an event id are retried as a message hash via
     * /v2/blockchain/messages/{hash}/transaction, then re-fetched as an event.
     *
     * @return array<string, mixed>|null
     */
    private function fetchEvent(string $txHash): ?array
    {
        $hash = $txHash;

        for ($attempt = 1; $attempt <= $this->lookupAttempts; $attempt++) {
            $response = $this->get('/v2/events/'.$hash);

            if ($response === null) {
                return null;
            }

            if ($response->successful()) {
                $event = $response->json();

                if (($event['in_progress'] ?? false) === true) {
                    Log::warning('Bridge: TON event still in progress', ['tx' => $hash]);
                } elseif (is_array($event)) {
                    return $event;
                }
            } elseif ($response->status() !== 404) {
                Log::warning('Bridge: tonapi error', [
                    'tx' => $hash,
                    'status' => $response->status(),
                ]);

                return null;
            } elseif ($hash === $txHash) {
                $resolved = $this->resolveMessageTransaction($txHash);

                if ($resolved !== null && $resolved !== $hash) {
                    // Found the transaction behind the external message —
                    // continue the loop against the real tx hash.
                    $hash = $resolved;

                    continue;
                }
            }

            if ($attempt < $this->lookupAttempts && $this->lookupDelaySeconds > 0) {
                sleep($this->lookupDelaySeconds);
            }
        }

        Log::warning('Bridge: TON event not found', ['tx' => $txHash]);

        return null;
    }

    /**
     * Resolve an external-in message hash to its transaction hash, or null
     * when tonapi has not indexed it (yet).
     */
    private function resolveMessageTransaction(string $msgHash): ?string
    {
        $response = $this->get('/v2/blockchain/messages/'.$msgHash.'/transaction');

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $hash = strtolower((string) $response->json('hash'));

        return preg_match('/^[0-9a-f]{64}$/', $hash) ? $hash : null;
    }

    private function get(string $path): ?Response
    {
        try {
            $request = Http::timeout(15)->acceptJson();

            if ($this->apiKey) {
                $request = $request->withToken($this->apiKey);
            }

            return $request->get(rtrim($this->baseUrl, '/').$path);
        } catch (\Throwable $e) {
            Log::error('Bridge: tonapi request failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Normalize a TON address to lowercase raw form "wc:hex64".
     * Accepts raw form directly or user-friendly base64url (EQ../UQ../kQ../0Q..,
     * 48 chars): decode 36 bytes = [tag, workchain, hash×32, crc×2]. Bounceable
     * and non-bounceable flavours of the same wallet normalize identically.
     */
    public static function normalizeAddress(string $address): ?string
    {
        $address = trim($address);

        if (preg_match('/^(-?\d+):([0-9a-fA-F]{64})$/', $address, $m)) {
            return $m[1].':'.strtolower($m[2]);
        }

        if (! preg_match('/^[A-Za-z0-9_\/+-]{48}$/', $address)) {
            return null;
        }

        $binary = base64_decode(strtr($address, '-_', '+/'), true);

        if ($binary === false || strlen($binary) !== 36) {
            return null;
        }

        $workchain = unpack('c', $binary[1])[1]; // signed byte (-1 for masterchain)
        $hash = bin2hex(substr($binary, 2, 32));

        return $workchain.':'.$hash;
    }

    /**
     * Normalize a TON tx/event hash to lowercase hex (64 chars).
     * Accepts hex directly or the 44-char base64/base64url encoding.
     */
    public static function normalizeTxHash(string $hash): ?string
    {
        $hash = trim($hash);

        if (preg_match('/^[0-9a-fA-F]{64}$/', $hash)) {
            return strtolower($hash);
        }

        if (preg_match('/^[A-Za-z0-9_\/+-]{43,44}={0,2}$/', $hash)) {
            $binary = base64_decode(strtr($hash, '-_', '+/'), true);

            if ($binary !== false && strlen($binary) === 32) {
                return bin2hex($binary);
            }
        }

        return null;
    }
}
