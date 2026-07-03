<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TON deposit verification via the tonapi.io REST API — no TON SDK needed
 * server-side (payouts are handled by crypto/ton/scripts/relay-jetton-transfer.ts).
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
     * (smallest units) or null.
     */
    public function verifyJettonDeposit(
        string $txHash,
        string $expectedSenderRaw,
        string $expectedJettonMasterRaw,
        string $expectedRecipientRaw,
    ): ?string {
        $event = $this->fetchEvent($txHash);

        if ($event === null) {
            Log::warning('Bridge: TON event not found', ['tx' => $txHash]);

            return null;
        }

        if (($event['in_progress'] ?? false) === true) {
            Log::warning('Bridge: TON event still in progress', ['tx' => $txHash]);

            return null;
        }

        foreach ($event['actions'] ?? [] as $action) {
            if (($action['type'] ?? '') !== 'JettonTransfer') {
                continue;
            }

            if (($action['status'] ?? '') !== 'ok') {
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

            return $amount;
        }

        Log::warning('Bridge: no matching JettonTransfer in TON event', [
            'tx' => $txHash,
            'expected_sender' => $expectedSenderRaw,
            'expected_jetton' => $expectedJettonMasterRaw,
        ]);

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchEvent(string $txHash): ?array
    {
        for ($attempt = 1; $attempt <= $this->lookupAttempts; $attempt++) {
            try {
                $request = Http::timeout(15)->acceptJson();

                if ($this->apiKey) {
                    $request = $request->withToken($this->apiKey);
                }

                $response = $request->get(
                    rtrim($this->baseUrl, '/').'/v2/events/'.$txHash,
                );

                if ($response->successful()) {
                    return $response->json();
                }

                if ($response->status() !== 404) {
                    Log::warning('Bridge: tonapi error', [
                        'tx' => $txHash,
                        'status' => $response->status(),
                    ]);

                    return null;
                }
            } catch (\Throwable $e) {
                Log::error('Bridge: tonapi request failed', [
                    'tx' => $txHash,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if ($attempt < $this->lookupAttempts && $this->lookupDelaySeconds > 0) {
                sleep($this->lookupDelaySeconds);
            }
        }

        return null;
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
