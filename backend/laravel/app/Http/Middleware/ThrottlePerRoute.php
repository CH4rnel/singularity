<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use RuntimeException;

/**
 * One bucket per endpoint, instead of one bucket per person.
 *
 * Laravel's own `throttle:N,1` composes its counter key out of *who* is asking
 * and nothing else — `sha1($user->id)` for a session, `sha1('|'.$ip)` for
 * everyone else. The route never enters it. That is fine when an application
 * has one limit; this one has forty, and they all count into the same tally,
 * so the effective limit for a person is the *strictest* number on any route
 * they touch.
 *
 * What that looked like from the outside: the wallet polls its mailbox every
 * seven seconds, re-reads prices every minute and reads the feed on every tab
 * change — nine or ten requests a minute before anybody types anything — and
 * `POST api/wallet/feed` is allowed ten. So writing a post answered "Too Many
 * Attempts" on the first press, having never been pressed. The same arithmetic
 * closed the chat's own 30-a-minute challenge behind the Solana relay's 240.
 *
 * Adding the route to the signature is the whole fix: a limit written on an
 * endpoint now means what it says, and a heavy poll can no longer spend an
 * allowance that belongs to something else. Named limiters (`throttle:login`)
 * do not come through here — they build their own keys — so Fortify's login
 * throttle is untouched.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    /**
     * @param  Request  $request
     * @return string
     */
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            throw new RuntimeException('Unable to generate the request signature. Route unavailable.');
        }

        /*
         * The method is in there beside the URI because a read and a write on
         * one address are two different endpoints with two different limits —
         * `GET api/wallet/chat/messages` is polled and `POST` to the same path
         * is somebody talking.
         */
        $endpoint = $request->method().' '.$route->getDomain().'|'.$route->uri();

        // Who is asking, by the same rule the framework uses: an account where
        // there is one, the address it came from where there is not.
        $who = ($user = $request->user()) !== null
            ? 'user:'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();

        return sha1($endpoint.'|'.$who);
    }
}
