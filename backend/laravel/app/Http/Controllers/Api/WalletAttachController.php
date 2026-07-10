<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\VerifyWalletSignature;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletNonce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletAttachController extends Controller
{
    /**
     * Attach an EVM wallet to the authenticated user's profile.
     */
    public function attachEvm(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'signature' => ['required', 'string'],
        ]);

        $walletAddress = Str::lower($request->string('wallet_address')->value());
        $signature = $request->string('signature')->value();

        // Check if another user already has this wallet
        $existing = User::where('wallet_address', $walletAddress)
            ->where('id', '!=', $request->user()->id)
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'This EVM wallet is already linked to another account.',
            ], 409);
        }

        // Verify the signature against the nonce
        $nonce = WalletNonce::where('wallet_address', $walletAddress)->first();

        if (! $nonce || $nonce->isExpired()) {
            return response()->json([
                'message' => 'Invalid or expired nonce. Please try again.',
            ], 422);
        }

        // Verify the signature recovers to the claimed address before binding
        // it — otherwise anyone could attach a wallet they do not control
        // (the nonce alone is not a secret: it is minted on request for any
        // address). The message prefix differs from login, so we recover the
        // signer directly rather than going through handle().
        $message = "Sign this message to link your wallet. Nonce: {$nonce->nonce}";
        $recovered = app(VerifyWalletSignature::class)->recover($message, $signature);

        if (Str::lower($recovered) !== $walletAddress) {
            $nonce->delete();

            return response()->json([
                'message' => 'Signature verification failed.',
            ], 401);
        }

        $nonce->delete();

        $request->user()->update([
            'wallet_address' => $walletAddress,
        ]);

        return response()->json([
            'message' => 'EVM wallet attached successfully.',
            'wallet_address' => $walletAddress,
        ]);
    }

    /**
     * Attach a Solana wallet to the authenticated user's profile.
     */
    public function attachSolana(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{32,44}$/'],
            'signature' => ['required', 'string'],
        ]);

        $walletAddress = $request->string('wallet_address')->value();
        $signature = $request->string('signature')->value();

        // Check if another user already has this wallet
        $existing = User::where('solana_wallet_address', $walletAddress)
            ->where('id', '!=', $request->user()->id)
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'This Solana wallet is already linked to another account.',
            ], 409);
        }

        // Verify the signature against the nonce
        $nonce = WalletNonce::where('wallet_address', $walletAddress)->first();

        if (! $nonce || $nonce->isExpired()) {
            return response()->json([
                'message' => 'Invalid or expired nonce. Please try again.',
            ], 422);
        }

        $message = "Sign this message to link your wallet. Nonce: {$nonce->nonce}";

        if (! $this->verifySolanaSignature($walletAddress, $message, $signature)) {
            $nonce->delete();

            return response()->json([
                'message' => 'Signature verification failed.',
            ], 401);
        }

        $nonce->delete();

        $request->user()->update([
            'solana_wallet_address' => $walletAddress,
        ]);

        return response()->json([
            'message' => 'Solana wallet attached successfully.',
            'solana_wallet_address' => $walletAddress,
        ]);
    }

    /**
     * Detach the EVM wallet from the authenticated user's profile.
     */
    public function detachEvm(Request $request): JsonResponse
    {
        $request->user()->update([
            'wallet_address' => null,
        ]);

        return response()->json([
            'message' => 'EVM wallet detached successfully.',
        ]);
    }

    /**
     * Detach the Solana wallet from the authenticated user's profile.
     */
    public function detachSolana(Request $request): JsonResponse
    {
        $request->user()->update([
            'solana_wallet_address' => null,
        ]);

        return response()->json([
            'message' => 'Solana wallet detached successfully.',
        ]);
    }

    /**
     * Verify an Ed25519 signature using PHP's sodium extension.
     */
    private function verifySolanaSignature(string $walletAddress, string $message, string $signatureBase64): bool
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

    /**
     * Decode a base58 string (Bitcoin/Solana alphabet).
     */
    private function base58Decode(string $input): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $base = strlen($alphabet);

        $num = gmp_init(0);
        for ($i = 0; $i < strlen($input); $i++) {
            $pos = strpos($alphabet, $input[$i]);
            if ($pos === false) {
                throw new \Exception('Invalid base58 character.');
            }
            $num = gmp_add(gmp_mul($num, $base), $pos);
        }

        $hex = gmp_strval($num, 16);
        if (strlen($hex) % 2 !== 0) {
            $hex = '0'.$hex;
        }

        $bytes = hex2bin($hex);

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
