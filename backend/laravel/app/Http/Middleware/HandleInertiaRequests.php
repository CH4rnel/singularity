<?php

namespace App\Http\Middleware;

use App\Services\Console\ConsoleHeader;
use App\Support\Changelog;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'release' => fn () => Changelog::shared(),
            'auth' => [
                'user' => $user,
                // Drives the CRM sidebar entry. The routes themselves are
                // guarded by EnsureCrmAdmin — this only hides dead links.
                'canAccessCrm' => EnsureCrmAdmin::allows($user),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            'notifications' => [
                'unread' => fn () => $user ? $user->unreadNotifications()->count() : 0,
            ],
            /*
             * What the analytics client is allowed to do, read at every
             * navigation rather than baked into the bundle — switching
             * collection off on the server switches it off in tabs that are
             * already open, instead of at the next deploy.
             */
            'analytics' => [
                'enabled' => (bool) config('analytics.enabled'),
                'respectDnt' => (bool) config('analytics.respect_dnt'),
                'sessionTimeoutMinutes' => (int) config('analytics.session_timeout_minutes'),
                'appVersion' => Changelog::currentVersion(),
            ],
            /*
             * The console's top bar, on every one of its lenses and nowhere
             * else. It is one state — the banner, the group counters and the
             * rail's badge are three renderings of one cached queue — so it is
             * shared rather than assembled per page, and the closure means an
             * ordinary page never pays for it.
             */
            'console' => fn () => $request->routeIs('crm.*') && EnsureCrmAdmin::allows($user)
                ? app(ConsoleHeader::class)->build()
                : null,
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
