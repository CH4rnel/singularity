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
        foreach ($this->apiUrls() as $apiUrl) {
            $balances = $this->addressBalancesFromApi($apiUrl, $address);

            if ($balances !== null) {
                return $balances;
            }
        }

        return null;
    }

    /**
     * @return array{confirmed: string, pending: string}|null
     */
    private function addressBalancesFromApi(string $apiUrl, string $address): ?array
    {
        $blockbook = $this->addressBalancesFromBlockbook($apiUrl, $address);

        if ($blockbook !== null) {
            return $blockbook;
        }

        return $this->addressBalancesFromUnspent($apiUrl, $address);
    }

    /**
     * Blockbook-compatible explorer2 endpoint. It returns confirmed and
     * unconfirmed balances as raw smallest-unit strings.
     *
     * @return array{confirmed: string, pending: string}|null
     */
    private function addressBalancesFromBlockbook(string $apiUrl, string $address): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->retry(2, 250)
                ->get($apiUrl.'/api/address/'.rawurlencode($address));

            if (! $response->successful()) {
                return null;
            }

            $confirmed = $response->json('balance');
            $pending = $response->json('unconfirmedBalance');

            if (! $this->validRawAmount($confirmed) || ! $this->validRawAmount($pending)) {
                return null;
            }

            return [
                'confirmed' => (string) $confirmed,
                'pending' => (string) $pending,
            ];
        } catch (\Throwable $exception) {
            Log::warning('Bridge: Yenten Blockbook balance lookup failed', [
                'address' => $address,
                'api_url' => $apiUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Legacy light-wallet endpoint. It returns UTXOs with values in satoshis.
     *
     * @return array{confirmed: string, pending: string}|null
     */
    private function addressBalancesFromUnspent(string $apiUrl, string $address): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->retry(2, 250)
                ->get($apiUrl.'/unspent/'.rawurlencode($address).'?amount=0');

            if (! $response->successful() || $response->json('error') !== null) {
                Log::warning('Bridge: Yenten unspent lookup failed', [
                    'address' => $address,
                    'api_url' => $apiUrl,
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
                'api_url' => $apiUrl,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function validRawAmount(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    /**
     * @return array<int, string>
     */
    private function apiUrls(): array
    {
        $configured = config('bridge.chains.yenten.balance_api_urls');
        $urls = is_array($configured) ? $configured : [];

        if ($urls === []) {
            $urls = [
                'https://explorer2.yentencoin.info',
                (string) config('bridge.chains.yenten.api_url', 'https://api.yentencoin.info'),
            ];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $url) => rtrim($url, '/'),
            $urls,
        ))));
    }
}
