<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a store build of the mobile app on the wallet.
 *
 * The Google Play and App Store builds of `frontend/mobile` render this site
 * and say so with `CyberiaStore/<edition>` in their user agent. A reviewer
 * judges everything those builds can reach, and the rest of the site carries
 * prediction markets, the slot machine and the launchpad, which neither store
 * takes from an unlicensed developer. So a page navigation from a store build
 * to anything outside `native.store.allowed_paths` is sent back to the wallet.
 *
 * Only page loads are confined. The wallet itself calls web routes
 * (`/bridge/submit`, `/login/web3`, `/wallets/*`, the DAO forms), and those are
 * POSTs or JSON reads that must keep working; the browser, the desktop app and
 * the sideloaded APK never carry the token and are untouched. The wallet's own
 * half of the rule is `resources/js/lib/storeEdition.ts`.
 */
class ConfineStoreEdition
{
    private const TOKEN = '/\bCyberiaStore\/(play|appstore)\b/';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->confines($request)) {
            return $next($request);
        }

        return redirect()->to('/wallet');
    }

    public static function edition(Request $request): ?string
    {
        return preg_match(self::TOKEN, (string) $request->userAgent(), $match) === 1 ? $match[1] : null;
    }

    private function confines(Request $request): bool
    {
        if (self::edition($request) === null || ! $request->isMethod('GET')) {
            return false;
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return false;
        }

        /** @var list<string> $allowed */
        $allowed = config('native.store.allowed_paths', []);

        return ! $request->is(...$allowed);
    }
}
