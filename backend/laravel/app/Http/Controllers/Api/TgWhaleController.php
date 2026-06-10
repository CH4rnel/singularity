<?php

namespace App\Http\Controllers\Api;

use App\Actions\Wallet\GenerateNonce;
use App\Actions\Wallet\ReadCyberSolBalance;
use App\Actions\Wallet\VerifySolanaOwnership;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Telegram "whales chat" gate.
 *
 * Flow: the bot hands the user a one-time link (`/tg/cyber-sol?t=<token>`; the
 * token row is written to `tg_link_tokens` by the bot). The page connects
 * Phantom and signs the nonce; `verify` checks the Ed25519 signature, reads the
 * wallet's CYBER.sol balance, and records the result in `tg_sol_wallets`. The
 * bot polls that table to invite/kick. Both processes share one SQLite file;
 * the `tg_*` tables are owned/created by the bot (see telegram_airdrop_bot.py),
 * so reads here are defensive — a missing table just means "no such token".
 */
class TgWhaleController extends Controller
{
    public function __construct(
        private GenerateNonce $generateNonce,
        private VerifySolanaOwnership $verifyOwnership,
        private ReadCyberSolBalance $readBalance,
    ) {}

    public function page(Request $request): View
    {
        $token = (string) $request->query('t', '');

        return view('tg.cyber-sol', [
            'token' => $token,
            'valid' => $token !== '' && $this->lookupToken($token) !== null,
            'threshold' => (int) config('services.cyber_sol.whale_threshold'),
        ]);
    }

    public function nonce(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{32,44}$/'],
        ]);

        return response()->json([
            'nonce' => $this->generateNonce->handle($request->string('wallet_address')->value()),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            't' => ['required', 'string'],
            'wallet_address' => ['required', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{32,44}$/'],
            'signature' => ['required', 'string'],
        ]);

        $link = $this->lookupToken($data['t']);
        if ($link === null) {
            return response()->json([
                'message' => 'This verification link is invalid or expired. Send /whale to the bot for a new one.',
            ], 422);
        }

        try {
            $this->verifyOwnership->handle($data['wallet_address'], $data['signature']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }

        try {
            $balance = $this->readBalance->handle($data['wallet_address']);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not read CYBER.sol balance, please try again. ('.$e->getMessage().')',
            ], 502);
        }

        $threshold = (int) config('services.cyber_sol.whale_threshold');
        $isWhale = $this->readBalance->meetsThreshold($balance['raw'], $threshold, $balance['decimals']);

        DB::table('tg_sol_wallets')->updateOrInsert(
            ['tg_user_id' => $link->tg_user_id],
            [
                'solana_address' => $data['wallet_address'],
                'balance_raw' => $balance['raw'],
                'is_whale' => $isWhale ? 1 : 0,
                'invited' => 0, // let the bot (re)issue the invite on its next tick
                'verified_at' => now()->toDateTimeString(),
                'last_checked_at' => now()->toDateTimeString(),
            ],
        );

        DB::table('tg_link_tokens')->where('token', $data['t'])->update(['used' => 1]);

        return response()->json([
            'balance' => $balance['amount'],
            'is_whale' => $isWhale,
            'threshold' => $threshold,
        ]);
    }

    /** The unused, unexpired link-token row, or null (also null if table absent). */
    private function lookupToken(string $token): ?object
    {
        try {
            $row = DB::table('tg_link_tokens')
                ->where('token', $token)
                ->where('used', 0)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        return strtotime((string) $row->expires_at) >= time() ? $row : null;
    }
}
