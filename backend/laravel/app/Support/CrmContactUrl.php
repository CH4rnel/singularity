<?php

namespace App\Support;

use App\Models\CrmContact;

class CrmContactUrl
{
    /** Turn a pasted contact address into a safe link without guessing a service. */
    public static function normalise(mixed $value): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            return null;
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) && preg_match('/^[^\s.]+\.[^\s]+/', $url)) {
            $url = 'https://'.$url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (in_array($scheme, ['mailto', 'tel'], true)) {
            return preg_match('/\s/', $url) === 1 ? null : $url;
        }

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $url;
    }

    public static function kind(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'mailto') {
            return 'email';
        }

        if ($scheme === 'tel') {
            return 'phone';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return match (true) {
            in_array($host, ['t.me', 'telegram.me', 'telegram.org'], true) => 'telegram',
            in_array($host, ['x.com', 'twitter.com'], true) => 'x',
            str_contains($host, 'discord.') || $host === 'discord.gg' => 'discord',
            $host === 'github.com' => 'github',
            str_contains($host, 'linkedin.com') => 'linkedin',
            str_contains($host, 'instagram.com') => 'instagram',
            str_contains($host, 'facebook.com') || $host === 'fb.me' => 'facebook',
            str_contains($host, 'whatsapp.com') || $host === 'wa.me' => 'whatsapp',
            str_contains($host, 'signal.me') => 'signal',
            default => 'link',
        };
    }

    public static function label(string $url): string
    {
        $kind = self::kind($url);

        if ($kind !== 'link') {
            return ucfirst($kind);
        }

        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    /** @return array<int, array{kind: string, label: string, url: string}> */
    public static function ways(CrmContact $contact): array
    {
        $ways = [];
        $telegram = Handles::telegramUrl($contact->telegram);
        $x = Handles::xUrl($contact->x_handle);

        if ($telegram !== null) {
            $ways[] = ['kind' => 'telegram', 'label' => 'Telegram', 'url' => $telegram];
        }

        if ($x !== null) {
            $ways[] = ['kind' => 'x', 'label' => 'X', 'url' => $x];
        }

        if ($contact->email !== null) {
            $ways[] = ['kind' => 'email', 'label' => $contact->email, 'url' => 'mailto:'.$contact->email];
        }

        foreach ($contact->contactLinks as $link) {
            $ways[] = ['kind' => $link->kind, 'label' => $link->label, 'url' => $link->url];
        }

        return collect($ways)->unique('url')->values()->all();
    }
}
