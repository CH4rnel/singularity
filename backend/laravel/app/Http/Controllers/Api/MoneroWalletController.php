<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\ValidMoneroAddress;
use App\Services\Monero\MoneroAddressCodec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's own Monero wallet — deliberately separate from
 * WalletAttachController (EVM/Solana).
 *
 * Monero has no browser wallet and no in-browser message signing, so there is
 * no nonce/signature handshake to run and no proof of ownership to be had.
 * The address is therefore a payout destination and nothing else: it never
 * authenticates anyone, never merges accounts and is not unique across users.
 * What *can* be verified is verified — the address' own Keccak checksum, via
 * ValidMoneroAddress — because an XMR payout is irreversible and untraceable.
 */
class MoneroWalletController extends Controller
{
    public function attach(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'max:106', new ValidMoneroAddress],
        ]);

        $address = trim((string) $validated['wallet_address']);

        $request->user()->update([
            'monero_wallet_address' => $address,
        ]);

        return response()->json([
            'message' => 'Monero wallet saved.',
            'monero_wallet_address' => $address,
            'kind' => MoneroAddressCodec::kind($address),
        ]);
    }

    public function detach(Request $request): JsonResponse
    {
        $request->user()->update([
            'monero_wallet_address' => null,
        ]);

        return response()->json([
            'message' => 'Monero wallet removed.',
        ]);
    }
}
