<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrmAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::allows($request->user()), 404);

        return $next($request);
    }

    /**
     * Whether this account is one of the operators (config/crm.php).
     *
     * Two names for the same short list: the account id, and the EVM wallet
     * attached to it. Either is enough — an operator who re-attaches a new
     * key keeps the console, and a database where the ids mean nothing (a
     * test, a local copy) still recognises the wallets.
     */
    public static function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (in_array((int) $user->getKey(), config('crm.admin_user_ids', []), true)) {
            return true;
        }

        $wallet = $user->wallet_address;

        if (! $wallet) {
            return false;
        }

        return in_array(strtolower($wallet), config('crm.admin_wallets', []), true);
    }
}
