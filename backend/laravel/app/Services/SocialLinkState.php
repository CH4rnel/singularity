<?php

namespace App\Services;

use App\Enums\SocialProvider;
use App\Exceptions\SocialIdentityConflictException;
use Illuminate\Http\Request;

class SocialLinkState
{
    private const MAX_AGE_SECONDS = 600;

    /**
     * @param  array<string, mixed>  $context
     */
    public function start(Request $request, SocialProvider $provider, array $context = []): void
    {
        $request->session()->put($this->key($provider), [
            ...$context,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'started_at' => now()->timestamp,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SocialIdentityConflictException
     */
    public function current(Request $request, SocialProvider $provider): array
    {
        $context = $request->session()->get($this->key($provider));
        $userId = $request->user()?->getAuthIdentifier();
        $startedAt = is_array($context) ? (int) ($context['started_at'] ?? 0) : 0;
        $age = now()->timestamp - $startedAt;

        if (
            ! is_array($context)
            || $userId === null
            || (string) ($context['user_id'] ?? '') !== (string) $userId
            || $startedAt <= 0
            || $age < 0
            || $age > self::MAX_AGE_SECONDS
        ) {
            throw SocialIdentityConflictException::invalidIntent();
        }

        return $context;
    }

    public function finish(Request $request, SocialProvider $provider): void
    {
        $request->session()->forget($this->key($provider));
    }

    private function key(SocialProvider $provider): string
    {
        return 'social_link.'.$provider->value;
    }
}
