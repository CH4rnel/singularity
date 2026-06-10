<?php

namespace App\Actions\Wallet;

use App\Models\WalletNonce;

/**
 * Proves ownership of a Solana wallet from a Phantom signature over the
 * one-time nonce challenge — the same Ed25519 scheme as VerifySolanaSignature,
 * but WITHOUT creating/authenticating a Laravel User. The Telegram whale gate
 * only needs proof the wallet is controlled by the requester, not an account.
 */
class VerifySolanaOwnership
{
    /** @throws \RuntimeException when the nonce or signature is missing/invalid. */
    public function handle(string $walletAddress, string $signatureBase64): void
    {
        $nonce = WalletNonce::where('wallet_address', $walletAddress)->first();

        if (! $nonce || $nonce->isExpired()) {
            throw new \RuntimeException('Invalid or expired nonce. Please request a new signature.');
        }

        $message = "Sign this message to authenticate with your wallet. Nonce: {$nonce->nonce}";

        if (! $this->verifyEd25519Signature($walletAddress, $message, $signatureBase64)) {
            throw new \RuntimeException('Signature verification failed.');
        }

        $nonce->delete();
    }

    private function verifyEd25519Signature(string $walletAddress, string $message, string $signatureBase64): bool
    {
        try {
            $signature = base64_decode($signatureBase64, true);
            if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
                return false;
            }

            $publicKey = $this->base58Decode($walletAddress);
            if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return false;
            }

            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\Exception) {
            return false;
        }
    }

    /** Decode a base58 string (Bitcoin/Solana alphabet) to raw bytes. */
    private function base58Decode(string $input): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);

        $num = gmp_init(0);
        for ($i = 0; $i < strlen($input); $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                throw new \RuntimeException('Invalid base58 character.');
            }
            $num = gmp_add(gmp_mul($num, $base), $pos);
        }

        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0'.$hex;
        }
        $bytes = hex2bin($hex);

        // Preserve leading zero bytes (each leading '1' in base58).
        $leadingZeros = 0;
        for ($i = 0; $i < strlen($input); $i++) {
            if ($input[$i] === '1') {
                $leadingZeros++;
            } else {
                break;
            }
        }

        return str_repeat("\x00", $leadingZeros).$bytes;
    }
}
