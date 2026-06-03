<?php

namespace App\Actions\Wallet;

use Elliptic\EC;
use kornrunner\Keccak;

/**
 * Recovers the EVM address that signed a personal-sign message.
 *
 * Personal-sign (EIP-191) prefixes the payload with
 *   "\x19Ethereum Signed Message:\n{len}{msg}"
 * before hashing — matches what MetaMask `eth_sign`/`personal_sign` produces.
 */
class RecoverEvmAddress
{
    private EC $ec;

    public function __construct()
    {
        $this->ec = new EC('secp256k1');
    }

    /** @return string 0x-prefixed lowercase address, or null if signature is malformed. */
    public function handle(string $message, string $signature): ?string
    {
        $sig = $this->parseSignature($signature);
        if (! $sig) {
            return null;
        }

        $msgHash = $this->hashPersonalMessage($message);

        // The recovery id MUST come from the signature's `v` byte. Several
        // recovery ids yield a mathematically valid (but different) public key
        // for the same r/s — only the one selected by `v` is the real signer.
        // Brute-forcing and taking the first that doesn't throw silently
        // returns the wrong address for ~half of all signatures.
        try {
            $pubKey = $this->ec->recoverPubKey(
                gmp_init($msgHash, 16),
                ['r' => gmp_init($sig['r'], 16), 's' => gmp_init($sig['s'], 16)],
                $sig['recId']
            );
            $p = $pubKey->encode('array');
            if (count($p) === 65 && $p[0] === 0x04) {
                array_shift($p);
            }

            return '0x'.substr($this->keccak256($p), 24);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseSignature(string $signature): ?array
    {
        $sig = trim($signature);
        if (str_starts_with($sig, '0x') || str_starts_with($sig, '0X')) {
            $sig = substr($sig, 2);
        }
        if (strlen($sig) !== 130) {
            return null;
        }

        // `v` is the last byte. personal_sign yields 27/28; some wallets and
        // EIP-2098 already encode 0/1. Normalize to a recovery id of 0 or 1.
        $v = hexdec(substr($sig, 128, 2));
        $recId = $v >= 27 ? $v - 27 : $v;
        if ($recId !== 0 && $recId !== 1) {
            return null;
        }

        return [
            'r' => '0x'.substr($sig, 0, 64),
            's' => '0x'.substr($sig, 64, 64),
            'recId' => $recId,
        ];
    }

    private function hashPersonalMessage(string $message): string
    {
        return $this->keccak256("\x19Ethereum Signed Message:\n".strlen($message).$message);
    }

    private function keccak256(string|array $data): string
    {
        if (is_array($data)) {
            $data = implode('', array_map('chr', $data));
        }

        return Keccak::hash($data, 256);
    }
}
