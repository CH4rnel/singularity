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

        match ($this->direction) {
            'evm_to_sol' => $this->validateSolana($trimmed, $fail),
            'sol_to_evm' => $this->validateEvm($trimmed, $fail),
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
