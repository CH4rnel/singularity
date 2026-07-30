<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Auth\LinkSocialIdentity;
use App\Enums\SocialProvider;
use App\Exceptions\SocialIdentityConflictException;
use App\Http\Controllers\Controller;
use App\Services\SocialLinkState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as OAuthUser;
use Throwable;
use UnexpectedValueException;

class GithubConnectionController extends Controller
{
    public function redirect(Request $request, SocialLinkState $linkState): RedirectResponse
    {
        if (! $this->configured()) {
            return to_route('profile.edit')
                ->with('error', 'GitHub linking is not configured yet.');
        }

        $linkState->start($request, SocialProvider::GitHub);

        try {
            return $this->provider()->redirect();
        } catch (Throwable $exception) {
            $linkState->finish($request, SocialProvider::GitHub);
            Log::warning('GitHub linking redirect failed', ['exception' => $exception::class]);

            return to_route('profile.edit')
                ->with('error', 'GitHub authorization could not be started. Please try again.');
        }
    }

    public function callback(
        Request $request,
        SocialLinkState $linkState,
        LinkSocialIdentity $linkIdentity,
    ): RedirectResponse {
        try {
            $linkState->current($request, SocialProvider::GitHub);

            /** @var OAuthUser $oauthUser */
            $oauthUser = $this->provider()->user();
            $githubId = (string) $oauthUser->getId();

            if ($githubId === '') {
                throw new UnexpectedValueException('GitHub did not return an account id.');
            }

            $linkState->finish($request, SocialProvider::GitHub);
            $linkIdentity->handle(
                $request->user(),
                SocialProvider::GitHub,
                $githubId,
                $oauthUser->getNickname(),
            );
        } catch (SocialIdentityConflictException $exception) {
            $linkState->finish($request, SocialProvider::GitHub);

            return to_route('profile.edit')->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $linkState->finish($request, SocialProvider::GitHub);
            Log::warning('GitHub linking callback failed', ['exception' => $exception::class]);

            return to_route('profile.edit')
                ->with('error', 'GitHub authorization was cancelled or failed. Please try again.');
        }

        return to_route('profile.edit')->with('status', SocialProvider::GitHub->linkedStatus());
    }

    private function configured(): bool
    {
        return (string) config('services.github.client_id') !== ''
            && (string) config('services.github.client_secret') !== '';
    }

    private function provider(): Provider
    {
        $provider = Socialite::driver('github');

        if ($provider instanceof AbstractProvider) {
            $provider->setScopes([]);
        }

        return $provider;
    }
}
