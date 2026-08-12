<?php

namespace App\Services\Predictions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use kornrunner\Keccak;

/**
 * Read side of the PredictionMarket contract.
 *
 * Plain eth_calls against the Cyberia RPC, decoded here, mirroring
 * ProfileOnchainService: the backend never signs, and the read path stays a
 * single HTTP call so the resolver can run on a short schedule without
 * spawning anything. The write path is PredictionsResolver.
 */
class PredictionMarketReader
{
    /** Rows per getMarkets() call — the node caps response sizes, not this. */
    private const PAGE = 100;

    public function contractAddress(): ?string
    {
        $address = (string) config('predictions.contract_address', '');

        return $address !== '' ? $address : null;
    }

    public function enabled(): bool
    {
        return $this->contractAddress() !== null;
    }

    /** Contract owner — the only address allowed to resolve. */
    public function oracle(): ?string
    {
        $result = $this->call('owner()', '');

        if ($result === null || strlen($result) < 66) {
            return null;
        }

        return '0x'.substr($result, 26, 40);
    }

    /**
     * Seconds after close before an unresolved market refunds and resolution
     * is disabled forever.
     *
     * Read from the contract rather than mirrored in config: the auto-cancel
     * safety net is timed against this number, and a config copy that drifted
     * from a redeployed contract would arm it too late — exactly when it is
     * the only thing standing between a market and permanent limbo.
     */
    public function resolveWindow(): int
    {
        $fallback = (int) config('predictions.resolve_window_seconds', 30 * 86400);

        $result = $this->call('RESOLVE_WINDOW()', '');

        if ($result === null) {
            return $fallback;
        }

        $window = (int) self::hexToDec(substr($result, 2));

        return $window > 0 ? $window : $fallback;
    }

    public function marketCount(): ?int
    {
        $result = $this->call('marketCount()', '');

        return $result === null ? null : (int) self::hexToDec(substr($result, 2));
    }

    /**
     * Every market on the contract, oldest first.
     *
     * @return array<int, array{id: int, creator: string, question: string, closeTime: int, resolvedAt: int, outcome: int, yesPool: string, noPool: string, feePaid: string}>|null
     */
    public function markets(): ?array
    {
        $count = $this->marketCount();

        if ($count === null) {
            return null;
        }

        $markets = [];

        for ($offset = 0; $offset < $count; $offset += self::PAGE) {
            $result = $this->call(
                'getMarkets(uint256,uint256)',
                self::encodeUint($offset).self::encodeUint(self::PAGE),
            );

            if ($result === null) {
                return null;
            }

            $page = self::decodeMarkets($result);

            if ($page === null) {
                return null;
            }

            $markets = [...$markets, ...$page];
        }

        return $markets;
    }

    /**
     * Decode the ABI return of getMarkets() — a dynamic array of structs whose
     * `question` member is itself dynamic, so every element is reached through
     * its own offset rather than by a fixed stride.
     *
     * @return array<int, array{id: int, creator: string, question: string, closeTime: int, resolvedAt: int, outcome: int, yesPool: string, noPool: string, feePaid: string}>|null
     */
    public static function decodeMarkets(string $result): ?array
    {
        $data = str_starts_with($result, '0x') ? substr($result, 2) : $result;

        // An empty array is still offset + length: anything shorter is not a
        // truncated answer we can salvage, it is not this function's answer.
        if (strlen($data) < 128) {
            return null;
        }

        $arrayAt = (int) self::hexToDec(substr($data, 0, 64)) * 2;
        $count = (int) self::hexToDec(substr($data, $arrayAt, 64));
        // Element offsets are relative to the first word after the length.
        $base = $arrayAt + 64;
        $markets = [];

        for ($i = 0; $i < $count; $i++) {
            $pointer = substr($data, $base + $i * 64, 64);

            if (strlen($pointer) !== 64) {
                return null;
            }

            $at = $base + (int) self::hexToDec($pointer) * 2;
            $market = self::decodeMarket($data, $at);

            if ($market === null) {
                return null;
            }

            $markets[] = $market;
        }

        return $markets;
    }

    /**
     * One MarketView struct at `$at`, a character offset into the payload.
     *
     * @return array{id: int, creator: string, question: string, closeTime: int, resolvedAt: int, outcome: int, yesPool: string, noPool: string, feePaid: string}|null
     */
    private static function decodeMarket(string $data, int $at): ?array
    {
        $word = static fn (int $index): string => substr($data, $at + $index * 64, 64);

        if (strlen($word(8)) !== 64) {
            return null;
        }

        // Head word 2 is the offset of `question`, relative to the struct.
        $questionAt = $at + (int) self::hexToDec($word(2)) * 2;
        $length = (int) self::hexToDec(substr($data, $questionAt, 64));
        $question = hex2bin(substr($data, $questionAt + 64, $length * 2));

        if ($question === false) {
            return null;
        }

        return [
            'id' => (int) self::hexToDec($word(0)),
            'creator' => '0x'.substr($word(1), 24, 40),
            'question' => $question,
            'closeTime' => (int) self::hexToDec($word(3)),
            'resolvedAt' => (int) self::hexToDec($word(4)),
            'outcome' => (int) self::hexToDec($word(5)),
            'yesPool' => self::hexToDec($word(6)),
            'noPool' => self::hexToDec($word(7)),
            'feePaid' => self::hexToDec($word(8)),
        ];
    }

    private function rpcUrl(): string
    {
        return (string) config('predictions.rpc_url')
            ?: (string) config('bridge.chains.cyberia.rpc_url', 'https://rpc.cyberia.church');
    }

    /** eth_call helper: 4-byte selector from the signature + raw hex args. */
    private function call(string $signature, string $argsHex): ?string
    {
        $contract = $this->contractAddress();

        if ($contract === null) {
            return null;
        }

        $selector = substr(Keccak::hash($signature, 256), 0, 8);

        try {
            $response = Http::timeout((int) config('predictions.rpc_timeout', 15))
                ->post($this->rpcUrl(), [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_call',
                    'params' => [
                        ['to' => $contract, 'data' => '0x'.$selector.$argsHex],
                        'latest',
                    ],
                ]);

            $result = $response->json('result');

            return is_string($result) && str_starts_with($result, '0x') ? $result : null;
        } catch (\Throwable $e) {
            Log::warning('Predictions read failed', [
                'signature' => $signature,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private static function encodeUint(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    /**
     * Hex word to a decimal string. Pools are wei and routinely exceed what a
     * PHP int holds, so they stay strings all the way to the log line.
     */
    private static function hexToDec(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');

        if ($hex === '') {
            return '0';
        }

        $dec = '0';

        for ($i = 0, $len = strlen($hex); $i < $len; $i++) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($hex[$i]));
        }

        return $dec;
    }
}
