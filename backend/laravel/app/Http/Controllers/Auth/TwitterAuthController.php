<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Teams\CreateTeam;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as OAuthUser;

/**
 * Log in / register with X (Twitter) via OAuth 2.0 + PKCE. The immutable
 * account id is the identity key; a signed-in user gets the X account linked
 * to their existing profile instead (same model as wallet attach).
 */
class TwitterAuthController extends Controller
{
    private const DRIVER = 'twitter-oauth-2';

    public function __construct(
        private readonly CreateTeam $createTeam,
    ) {}

    public function redirect(): RedirectResponse
    {
        return Socialite::driver(self::DRIVER)->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            /** @var OAuthUser $oauthUser */
            $oauthUser = Socialite::driver(self::DRIVER)->user();
        } catch (\Throwable $e) {
            Log::warning('Twitter auth: callback failed', ['error' => $e->getMessage()]);

            return redirect()
                ->route('login')
                ->with('error', 'X authorization was cancelled or failed. Please try again.');
        }

        $twitterId = (string) $oauthUser->getId();

        if ($twitterId === '') {
            return redirect()
                ->route('login')
                ->with('error', 'X did not return an account id.');
        }

        $existing = User::where('twitter_id', $twitterId)->first();

        // A signed-in user is LINKING the X account, not logging in.
        if (Auth::check()) {
            $user = Auth::user();

            if ($existing && ! $existing->is($user)) {
                return redirect()
                    ->route('profile.edit')
                    ->with('error', 'This X account is already linked to another user.');
            }

            $user->forceFill([
                'twitter_id' => $twitterId,
                'twitter_username' => $oauthUser->getNickname(),
            ])->save();

            return redirect()
                ->route('profile.edit')
                ->with('status', 'X account linked.');
        }

        $user = $existing ?? $this->register($oauthUser, $twitterId);

        // Keep the handle fresh — users can rename it on X.
        if ($user->twitter_username !== $oauthUser->getNickname()) {
            $user->forceFill(['twitter_username' => $oauthUser->getNickname()])->save();
        }

        request()->session()->regenerate();
        Auth::login($user, true);

        return redirect()->intended(route('home'));
    }

    /**
     * First X login: create the user with a placeholder email (same pattern
     * as wallet-only accounts) and a personal team.
     */
    private function register(OAuthUser $oauthUser, string $twitterId): User
    {
        return DB::transaction(function () use ($oauthUser, $twitterId) {
            $user = User::create([
                'name' => $oauthUser->getName()
                    ?: ($oauthUser->getNickname() ? '@'.$oauthUser->getNickname() : "X {$twitterId}"),
                'email' => $oauthUser->getEmail() ?: "twitter_{$twitterId}@localhost",
                'password' => null,
            ]);

            $user->forceFill([
                'twitter_id' => $twitterId,
                'twitter_username' => $oauthUser->getNickname(),
            ])->save();

            $this->createTeam->handle($user, "User's Team", isPersonal: true);

            return $user;
        });
    }
}
