<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\LinkSocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\SocialIdentityConflictException;
use App\Http\Controllers\Controller;
use App\Services\TelegramOidcService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramConnectionController extends Controller
{
    public function redirect(
        Request $request,
        TelegramOidcService $telegram,
    ): RedirectResponse {
        if (! $telegram->configured()) {
            return to_route('profile.edit')
                ->with('error', 'Telegram linking is not configured yet.');
        }

        try {
            return redirect()->away($telegram->authorizationUrl($request));
        } catch (Throwable $exception) {
            $telegram->cancel($request);
            Log::warning('Telegram linking redirect failed', ['exception' => $exception::class]);

            return to_route('profile.edit')
                ->with('error', 'Telegram authorization could not be started. Please try again.');
        }
    }

    public function callback(
        Request $request,
        TelegramOidcService $telegram,
        LinkSocialIdentity $linkIdentity,
    ): RedirectResponse {
        try {
            $identity = $telegram->identity($request);
            $linkIdentity->handle(
                $request->user(),
                SocialProvider::Telegram,
                $identity['id'],
                $identity['username'],
            );
        } catch (SocialIdentityConflictException $exception) {
            $telegram->cancel($request);

            return to_route('profile.edit')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $telegram->cancel($request);
            Log::warning('Telegram linking callback failed', ['exception' => $exception::class]);

            return to_route('profile.edit')
                ->with('error', 'Telegram authorization was cancelled or failed. Please try again.');
        }

        return to_route('profile.edit')->with('status', SocialProvider::Telegram->linkedStatus());
    }
}
