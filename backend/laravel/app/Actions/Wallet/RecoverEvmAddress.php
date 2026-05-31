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

        // EIP-2098 / canonical sigs include v; we ignore the supplied v and
        // brute-force the recovery id by trying all four valid candidates.
        for ($recId = 0; $recId < 4; $recId++) {
            try {
                $pubKey = $this->ec->recoverPubKey(
                    gmp_init($msgHash, 16),
                    ['r' => gmp_init($sig['r'], 16), 's' => gmp_init($sig['s'], 16)],
                    $recId
                );
                $p = $pubKey->encode('array');
                if (count($p) === 65 && $p[0] === 0x04) {
                    array_shift($p);
                }

                return '0x'.substr($this->keccak256($p), 24);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
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

        return [
            'r' => '0x'.substr($sig, 0, 64),
            's' => '0x'.substr($sig, 64, 64),
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
