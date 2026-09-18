<?php

use App\Exceptions\AiApiException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\ThrottlePerRoute;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The tracker's announce and scrape endpoints, outside every group:
        // torrent clients call them several times an hour and have no use for
        // a session, a cookie or a CSRF token. See routes/tracker.php.
        then: fn () => Route::group([], __DIR__.'/../routes/tracker.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        /*
         * Every `throttle:N,1` in this application counts into a bucket of its
         * own. Laravel's key is the caller and not the route, which made the
         * strictest limit anybody touched the limit for everything they did —
         * see App\Http\Middleware\ThrottlePerRoute.
         */
        $middleware->alias(['throttle' => ThrottlePerRoute::class]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Anonymous funnel ingest must also work from the static landing,
        // which has no CSRF token. Throttled + whitelisted-events only.
        $middleware->validateCsrfTokens(except: ['api/events']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The inference API answers in OpenAI's error envelope, including when
        // it is the one refusing: a client pointed at this host should never
        // have to parse two error shapes depending on who said no.
        $exceptions->render(fn (AiApiException $e) => $e->toResponse());
    })->create();
