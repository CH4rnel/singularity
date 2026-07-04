<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YentenApiService
{
    /**
     * Confirmed (spendable) balance on an address, in satoshis. Used to mint
     * whatever the user deposited to their one-time address — no amount or
     * tx hash needed. Returns null on API failure, '0' when nothing is there.
     */
    public function addressBalance(string $address): ?string
    {
        $balances = $this->addressBalances($address);

        return $balances === null ? null : $balances['confirmed'];
    }

    /**
     * Confirmed and still-unconfirmed balances on an address, in satoshis:
     * ['confirmed' => ..., 'pending' => ...]. Only confirmed coins (height > 0)
     * are mintable — mirrors what the relay is willing to spend; pending lets
     * the UI say "deposit seen, waiting for a confirmation" instead of
     * pretending nothing arrived. Returns null on API failure.
     *
     * @return array{confirmed: string, pending: string}|null
     */
    public function addressBalances(string $address): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->retry(2, 250)
                ->get($this->apiUrl().'/unspent/'.rawurlencode($address).'?amount=0');

            if (! $response->successful() || $response->json('error') !== null) {
                Log::warning('Bridge: Yenten unspent lookup failed', [
                    'address' => $address,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $utxos = $response->json('result');

            if (! is_array($utxos)) {
                return ['confirmed' => '0', 'pending' => '0'];
            }

            $confirmed = '0';
            $pending = '0';

            foreach ($utxos as $utxo) {
                $value = is_array($utxo) ? ($utxo['value'] ?? null) : null;

                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    continue;
                }

                $height = is_array($utxo) ? ($utxo['height'] ?? 0) : 0;

                if (is_int($height) && $height > 0) {
                    $confirmed = bcadd($confirmed, (string) $value, 0);
                } else {
                    $pending = bcadd($pending, (string) $value, 0);
                }
            }

            return ['confirmed' => $confirmed, 'pending' => $pending];
        } catch (\Throwable $exception) {
            Log::error('Bridge: Yenten address balance lookup failed', [
                'address' => $address,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function apiUrl(): string
    {
        return rtrim((string) config('bridge.chains.yenten.api_url', 'https://api.yentencoin.info'), '/');
    }
}
