<?php

namespace App\Support;

class Changelog
{
    public static function currentVersion(): string
    {
        return (string) config('changelog.current_version', data_get(self::releases(), '0.version', 'v0.0.0'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function releases(): array
    {
        $releases = config('changelog.releases', []);

        if (! is_array($releases)) {
            return [];
        }

        return array_values($releases);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function recent(int $limit = 3): array
    {
        return array_slice(self::releases(), 0, $limit);
    }

    /**
     * @return array{current: array{version: string}, recent: array<int, array<string, mixed>>, changelogUrl: string}
     */
    public static function shared(): array
    {
        return [
            'current' => [
                'version' => self::currentVersion(),
            ],
            'recent' => self::recent(),
            'changelogUrl' => route('changelog'),
        ];
    }
}
