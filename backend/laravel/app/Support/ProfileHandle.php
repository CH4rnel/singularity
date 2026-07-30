<?php

namespace App\Support;

final class ProfileHandle
{
    public const PATTERN = '[a-z0-9_]{3,20}';

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
