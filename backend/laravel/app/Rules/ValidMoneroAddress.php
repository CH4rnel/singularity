<?php

namespace App\Rules;

use App\Services\Monero\MoneroAddressCodec;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A native mainnet Monero address: standard (4...), integrated (4..., with an
 * embedded payment id) or subaddress (8...), each verified against its
 * Keccak-256 checksum. Monero is never represented by its EVM wrapper here —
 * an 0x address is a different asset on a different chain and is rejected with
 * that in the message.
 */
class ValidMoneroAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        $address = trim($value);

        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            $fail('This is an EVM address. Paste a native Monero address (4... or 8...).');

            return;
        }

        if (MoneroAddressCodec::isValid($address)) {
            return;
        }

        // Right shape, wrong content: almost always a typo or a truncated
        // copy/paste, which is worth saying out loud.
        if (preg_match('/^[48][123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{94}([123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]{11})?$/', $address)) {
            $fail('Monero address checksum is invalid — check for a typo.');

            return;
        }

        $fail('Not a valid Monero address.');
    }
}
