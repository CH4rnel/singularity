<?php

namespace App\Support;

final class ProfileHandle
{
    public const PATTERN = '[a-z0-9_]{3,20}';

    /**
     * The pattern the root profile route matches on.
     *
     * The same shape as PATTERN with the reserved handles cut out of it, so a
     * request for one of them falls through to whatever route actually owns
     * that path instead of being swallowed here and answered as a missing
     * profile. That matters for any route registered *after* this one — the
     * tracker's `/announce` and `/scrape` are outside every middleware group
     * and therefore registered last.
     */
    public static function routePattern(): string
    {
        $reserved = array_map(preg_quote(...), self::reserved());

        return $reserved === []
            ? self::PATTERN
            : '(?!(?:'.implode('|', $reserved).')$)'.self::PATTERN;
    }

    public static function isCanonical(?string $handle): bool
    {
        return is_string($handle)
            && preg_match('/\A'.self::PATTERN.'\z/D', $handle) === 1
            && ! in_array($handle, self::reserved(), true);
    }

    public static function url(int $userId, ?string $handle): string
    {
        if (self::isCanonical($handle)) {
            return route('users.show', ['user' => $handle], absolute: false);
        }

        return route('users.legacy', ['user' => $userId], absolute: false);
    }

    /**
     * @return array<int, string>
     */
    public static function reserved(): array
    {
        return config('profile.reserved_handles', []);
    }
}
