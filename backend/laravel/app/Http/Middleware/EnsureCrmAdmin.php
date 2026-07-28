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
     * Whether this user's attached EVM wallet is on the CRM allow list.
     */
    public static function allows(?User $user): bool
    {
        $wallet = $user?->wallet_address;

        if (! $wallet) {
            return false;
        }

        return in_array(strtolower($wallet), config('crm.admin_wallets', []), true);
    }
}
