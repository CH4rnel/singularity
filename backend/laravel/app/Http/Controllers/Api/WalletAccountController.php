<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Deleting the Cyberia account from inside the wallet.
 *
 * The wallet makes an account the moment somebody signs the login challenge
 * to post, vote or check in, and both stores require that an app which lets
 * people create an account lets them delete it from the app as well. The
 * site's own form (`Settings\ProfileController::destroy`) asks for a password,
 * which an account made by a signature has never had — so here the proof is a
 * signature instead: the wallet signs the login challenge again right before
 * asking, and anything older than `FRESH_SECONDS` is refused rather than
 * trusted to a cookie that could have been sitting in a borrowed phone.
 *
 * What goes is the account and everything keyed to it (posts, XP, sessions).
 * Nothing on chain and nothing in the wallet is touched: the keys were never
 * here, and a transaction cannot be deleted by anybody.
 */
class WalletAccountController extends Controller
{
    public const FRESH_SECONDS = 600;

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'string', 'in:delete'],
        ]);

        $signedAt = (int) $request->session()->get('auth.wallet_signed_at', 0);

        if ($signedAt === 0 || time() - $signedAt > self::FRESH_SECONDS) {
            return response()->json([
                'message' => 'Sign with the wallet again to delete this account.',
            ], 423);
        }

        $user = $request->user();

        Auth::guard('web')->logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['deleted' => true]);
    }
}
