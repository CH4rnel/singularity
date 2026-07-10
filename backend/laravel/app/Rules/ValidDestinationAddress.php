<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use kornrunner\Keccak;

class ValidDestinationAddress implements ValidationRule
{
    public function __construct(
        private string $direction
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            $fail('Destination address is required.');

            return;
        }

        $route = config('bridge.routes', [])[$this->direction] ?? null;

        if (! is_array($route)) {
            $fail('Unknown direction.');

            return;
        }

        $destinationChain = (string) ($route['destination_chain'] ?? '');
        $chain = config('bridge.chains', [])[$destinationChain] ?? null;
        $addressType = is_array($chain) ? ($chain['address_type'] ?? null) : null;

        match ($addressType) {
            'evm' => $this->validateEvm($trimmed, $fail),
            'solana' => $this->validateSolana($trimmed, $fail),
            'ton' => $this->validateTon($trimmed, $fail),
            'yenten' => $this->validateYenten($trimmed, $fail),
            'bitcoin' => $this->validateBitcoin($trimmed, $fail),
            'litecoin' => $this->validateLitecoin($trimmed, $fail),
            'monero' => $this->validateMonero($trimmed, $fail),
            default => $fail('Unknown direction.'),
        };
    }

    private function validateEvm(string $address, Closure $fail): void
    {
        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            if (self::looksLikeSolana($address)) {
                $fail('This looks like a Solana address. Use an EVM (0x...) address.');

                return;
            }
            $fail('Not a valid EVM address (expected 0x + 40 hex chars).');

            return;
        }

        $hex = substr($address, 2);
        $hasMixedCase = preg_match('/[a-f]/', $hex) && preg_match('/[A-F]/', $hex);

        if ($hasMixedCase && ! self::passesEip55Checksum($address)) {
            $fail('EVM address checksum is invalid.');
        }
    }

    private function validateSolana(string $address, Closure $fail): void
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            $fail('This looks like an EVM address. Use a Solana address.');

            return;
        }

        if (! preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
            $fail('Not a valid Solana address (expected base58, 32-44 chars).');

            return;
        }

        $decoded = self::base58Decode($address);

        if ($decoded === null || strlen($decoded) !== 32) {
            $fail('Not a valid Solana address.');
        }
    }

    private function validateTon(string $address, Closure $fail): void
    {
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            $fail('This looks like an EVM address. Use a TON address.');

            return;
        }

        $raw = preg_match('/^-?\d+:[0-9a-fA-F]{64}$/', $address) === 1;
        $friendly = preg_match('/^[A-Za-z0-9_-]{48}$/', $address) === 1;

        if (! $raw && ! $friendly) {
            $fail('Not a valid TON address.');
        }
    }

    private function validateYenten(string $address, Closure $fail): void
    {
        if (! preg_match('/^[1-9A-HJ-NP-Za-km-z]{26,35}$/', $address)) {
            $fail('Not a valid Yenten address. Use a legacy Y... address.');

            return;
        }

        $decoded = self::base58Decode($address);

        if ($decoded === null || strlen($decoded) !== 25 || ord($decoded[0]) !== 0x4E) {
            $fail('Not a valid Yenten address. Use a legacy Y... address.');

            return;
        }

        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        if (! hash_equals($expected, $checksum)) {
            $fail('Yenten address checksum is invalid.');
        }
    }

    private function validateBitcoin(string $address, Closure $fail): void
    {
        if ($this->validateBech32Like($address, 'bc')) {
            return;
        }

        if (! $this->validateBase58CheckVersion($address, [0x00, 0x05])) {
            $fail('Not a valid Bitcoin address.');
        }
    }

    private function validateLitecoin(string $address, Closure $fail): void
    {
        if ($this->validateBech32Like($address, 'ltc')) {
            return;
        }

        // 0x30 = L..., 0x32 = M..., 0x05 = legacy 3... P2SH.
        if (! $this->validateBase58CheckVersion($address, [0x30, 0x32, 0x05])) {
            $fail('Not a valid Litecoin address.');
        }
    }

    private function validateMonero(string $address, Closure $fail): void
    {
        // Monero uses its own base58 chunking and checksum. Keep this as a
        // conservative format guard; native-chain verification remains manual.
        if (! preg_match('/^[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{95}([123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{11})?$/', $address)) {
            $fail('Not a valid Monero address.');

            return;
        }

        if (! str_starts_with($address, '4') && ! str_starts_with($address, '8')) {
            $fail('Not a valid Monero address.');
        }
    }

    private function validateBech32Like(string $address, string $hrp): bool
    {
        $lower = strtolower($address);

        if ($address !== $lower && $address !== strtoupper($address)) {
            return false;
        }

        return preg_match('/^'.preg_quote($hrp, '/').'1[ac-hj-np-z02-9]{11,71}$/', $lower) === 1;
    }

    /**
     * @param  array<int, int>  $versions
     */
    private function validateBase58CheckVersion(string $address, array $versions): bool
    {
        if (! preg_match('/^[1-9A-HJ-NP-Za-km-z]{26,35}$/', $address)) {
            return false;
        }

        $decoded = self::base58Decode($address);

        if ($decoded === null || strlen($decoded) !== 25 || ! in_array(ord($decoded[0]), $versions, true)) {
            return false;
        }

        return self::passesBase58Check($decoded);
    }

    private static function passesBase58Check(string $decoded): bool
    {
        if (strlen($decoded) < 5) {
            return false;
        }

        $payload = substr($decoded, 0, -4);
        $checksum = substr($decoded, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return hash_equals($expected, $checksum);
    }

    private static function looksLikeSolana(string $s): bool
    {
        return preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $s) === 1
            && ! preg_match('/^0x[0-9a-fA-F]+$/', $s);
    }

    private static function passesEip55Checksum(string $address): bool
    {
        $hex = strtolower(substr($address, 2));
        try {
            $hash = Keccak::hash($hex, 256);
        } catch (\Throwable) {
            return false;
        }

        for ($i = 0; $i < 40; $i++) {
            $char = $hex[$i];
            $expected = hexdec($hash[$i]) >= 8 ? strtoupper($char) : $char;
            if ($address[$i + 2] !== $expected) {
                return false;
            }
        }

        return true;
    }

    private static function base58Decode(string $input): ?string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $num = '0';

        for ($i = 0, $len = strlen($input); $i < $len; $i++) {
            $idx = strpos($alphabet, $input[$i]);
            if ($idx === false) {
                return null;
            }
            $num = bcadd(bcmul($num, '58'), (string) $idx);
        }

        $bytes = '';
        while (bccomp($num, '0') > 0) {
            $bytes = chr((int) bcmod($num, '256')).$bytes;
            $num = bcdiv($num, '256', 0);
        }

        // Leading zero bytes: each leading '1' in base58 means a 0x00 byte.
        for ($i = 0; $i < strlen($input) && $input[$i] === '1'; $i++) {
            $bytes = "\x00".$bytes;
        }

        return $bytes;
    }
}
