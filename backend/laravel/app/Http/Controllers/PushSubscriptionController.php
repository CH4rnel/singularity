<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePushSubscriptionRequest;
use App\Support\Localised;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(StorePushSubscriptionRequest $request): JsonResponse
    {
        $request->user()->updatePushSubscription(
            $request->input('endpoint'),
            $request->input('keys.p256dh'),
            $request->input('keys.auth'),
            $request->input('contentEncoding'),
        );

        // A push notification is composed hours later with no browser present,
        // so the language has to be remembered now or guessed then.
        $locale = Localised::normalise($request->input('locale'));

        if ($locale !== null && $locale !== $request->user()->notification_locale) {
            $request->user()->forceFill(['notification_locale' => $locale])->save();
        }

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string']]);

        $request->user()->deletePushSubscription($request->input('endpoint'));

        return response()->json(['subscribed' => false]);
    }
}
