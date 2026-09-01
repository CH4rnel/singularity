<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsUser;
use App\Support\Localised;
use App\Support\VapidHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Letting a wallet be notified, without asking it to have an account.
 *
 * The site's own bell hangs off a signed-in user, and almost nobody running
 * this wallet is signed in — the PWA, the native shells and the Telegram mini
 * app do not even render the header the bell lives in. So the button existed
 * in exactly one place that most people never see, and push could reach the
 * site's handful of accounts and none of the installations.
 *
 * The subscription is stored against the installation UUID the analytics
 * client already mints on first run. That is a deliberate choice over the EVM
 * address: an address would need a signature before the person has any reason
 * to give one, and one person holds several. Address-targeted notifications —
 * "your bridge payout landed" — need proof of the key and are a separate step
 * on top of this one, not a different foundation.
 *
 * Credential-less like the rest of /api/analytics: no session, no cookie. The
 * worst a forged UUID can do is subscribe a device to notifications for an
 * installation that is not it, which is why nothing here reveals anything
 * about the installation in return.
 */
class WalletPushController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'endpoint' => ['required', 'url', 'max:500'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:12'],
        ]);

        if (! VapidHealth::check()['ok']) {
            // Saying "subscribed" over keys that cannot sign is how this
            // feature spent its first day.
            return response()->json(['subscribed' => false, 'reason' => 'unconfigured'], 503);
        }

        $install = AnalyticsUser::find($validated['user_id']);

        if ($install === null) {
            // The analytics client mints the UUID and sends its first event
            // before anything can subscribe, so an unknown id is a client that
            // never started rather than a device to remember.
            return response()->json(['subscribed' => false, 'reason' => 'unknown'], 404);
        }

        $install->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth'],
        );

        $locale = Localised::normalise($validated['locale'] ?? null);

        if ($locale !== null && $locale !== $install->notification_locale) {
            $install->forceFill(['notification_locale' => $locale])->save();
        }

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        AnalyticsUser::find($validated['user_id'])
            ?->deletePushSubscription($validated['endpoint']);

        return response()->json(['subscribed' => false]);
    }
}
