<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\VerifyWalletSignature;
use App\Exceptions\AccountMergeConflictException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletNonce;
use App\Services\AccountMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletAttachController extends Controller
{
    public function __construct(
        private readonly VerifyWalletSignature $verifyWalletSignature,
        private readonly AccountMergeService $accountMerge,
    ) {}

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

        // Verify the signature against the nonce
        $nonce = WalletNonce::where('wallet_address', $walletAddress)->first();

        if (! $nonce || $nonce->isExpired()) {
            return response()->json([
                'message' => 'Invalid or expired nonce. Please try again.',
            ], 422);
        }

        // Verify the signature recovers to the claimed address before doing
        // anything with it — otherwise anyone could attach (and, below,
        // merge in) a wallet they do not control (the nonce alone is not a
        // secret: it is minted on request for any address). The message
        // prefix differs from login, so we recover the signer directly
        // rather than going through handle().
        $message = "Sign this message to link your wallet. Nonce: {$nonce->nonce}";
        $recovered = $this->verifyWalletSignature->recover($message, $signature);

        if (Str::lower($recovered) !== $walletAddress) {
            $nonce->delete();

            return response()->json([
                'message' => 'Signature verification failed.',
            ], 401);
        }

        $nonce->delete();

        // Ownership of the wallet is now proven, so it's safe to check
        // whether another account already holds it and, if so, merge that
        // account's data into this one instead of blocking the user.
        $owner = User::where('wallet_address', $walletAddress)
            ->where('id', '!=', $request->user()->id)
            ->first();

        if ($owner) {
            try {
                $this->accountMerge->merge($request->user(), $owner);
            } catch (AccountMergeConflictException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return response()->json([
                'message' => 'Your accounts have been merged and this EVM wallet is now attached.',
                'wallet_address' => $walletAddress,
                'merged' => true,
            ]);
        }

        $request->user()->forceFill([
            'wallet_address' => $walletAddress,
            'onchain_nickname' => null,
        ])->save();

        return response()->json([
            'message' => 'EVM wallet attached successfully.',
            'wallet_address' => $walletAddress,
            'merged' => false,
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

        // Ownership of the wallet is now proven, so it's safe to check
        // whether another account already holds it and, if so, merge that
        // account's data into this one instead of blocking the user.
        $owner = User::where('solana_wallet_address', $walletAddress)
            ->where('id', '!=', $request->user()->id)
            ->first();

        if ($owner) {
            try {
                $this->accountMerge->merge($request->user(), $owner);
            } catch (AccountMergeConflictException $e) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            return response()->json([
                'message' => 'Your accounts have been merged and this Solana wallet is now attached.',
                'solana_wallet_address' => $walletAddress,
                'merged' => true,
            ]);
        }

        $request->user()->update([
            'solana_wallet_address' => $walletAddress,
        ]);

        return response()->json([
            'message' => 'Solana wallet attached successfully.',
            'solana_wallet_address' => $walletAddress,
            'merged' => false,
        ]);
    }

    /**
     * Detach the EVM wallet from the authenticated user's profile.
     */
    public function detachEvm(Request $request): JsonResponse
    {
        $request->user()->forceFill([
            'wallet_address' => null,
            'onchain_nickname' => null,
        ])->save();

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
