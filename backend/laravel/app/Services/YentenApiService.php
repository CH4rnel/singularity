<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YentenApiService
{
    public function verifyDeposit(
        string $txHash,
        string $expectedSender,
        string $expectedRecipient,
        int $minimumConfirmations = 1,
    ): ?string {
        if (! preg_match('/^[0-9a-fA-F]{64}$/', $txHash)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->retry(2, 250)
                ->get($this->apiUrl().'/transaction/'.strtolower($txHash));

            if (! $response->successful() || $response->json('error') !== null) {
                Log::warning('Bridge: Yenten API transaction lookup failed', [
                    'tx' => $txHash,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $transaction = $response->json('result');

            if (! is_array($transaction)
                || strtolower((string) ($transaction['txid'] ?? '')) !== strtolower($txHash)
                || (int) ($transaction['confirmations'] ?? 0) < $minimumConfirmations) {
                return null;
            }

            if (! $this->hasInputFrom($transaction['vin'] ?? [], $expectedSender)) {
                Log::warning('Bridge: Yenten deposit sender mismatch', ['tx' => $txHash]);

                return null;
            }

            $received = $this->sumOutputsTo($transaction['vout'] ?? [], $expectedRecipient);

            return bccomp($received, '0', 0) > 0 ? $received : null;
        } catch (\Throwable $exception) {
            Log::error('Bridge: Yenten deposit verification failed', [
                'tx' => $txHash,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function hasInputFrom(mixed $inputs, string $expectedSender): bool
    {
        if (! is_array($inputs)) {
            return false;
        }

        foreach ($inputs as $input) {
            if (! is_array($input)) {
                continue;
            }

            $addresses = $input['scriptPubKey']['addresses'] ?? [];

            if (is_array($addresses) && in_array($expectedSender, $addresses, true)) {
                return true;
            }
        }

        return false;
    }

    private function sumOutputsTo(mixed $outputs, string $expectedRecipient): string
    {
        if (! is_array($outputs)) {
            return '0';
        }

        $total = '0';

        foreach ($outputs as $output) {
            if (! is_array($output)) {
                continue;
            }

            $addresses = $output['scriptPubKey']['addresses'] ?? [];

            if (! is_array($addresses) || ! in_array($expectedRecipient, $addresses, true)) {
                continue;
            }

            $value = $output['value'] ?? null;

            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $total = bcadd($total, (string) $value, 0);
            }
        }

        return $total;
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('bridge.chains.yenten.api_url', 'https://api.yentencoin.info'), '/');
    }
}
