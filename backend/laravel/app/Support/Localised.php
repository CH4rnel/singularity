<?php

namespace App\Support;

/**
 * Server-side text in the three languages the product speaks.
 *
 * The interface picks its own language in the browser and Laravel is
 * deliberately not told (`useLocale.ts`) — a rendered page always has a person
 * in front of it. Notifications are the exception this class exists for: they
 * are composed by a scheduled command hours after anyone was looking, so the
 * language has to have been remembered, and something has to choose the words.
 *
 * The rule from the frontend dictionaries is kept exactly: only `en` is
 * mandatory, and anything missing falls back to it rather than rendering a key.
 */
class Localised
{
    public const LOCALES = ['en', 'ru', 'zh'];

    /**
     * Reduce whatever a browser called itself to one of our three languages.
     * Every `zh-*` lands on Simplified, as it does on the client.
     */
    public static function normalise(mixed $tag): ?string
    {
        $tag = mb_strtolower(trim((string) $tag));

        if ($tag === '') {
            return null;
        }

        $base = explode('-', $tag)[0];

        return in_array($base, self::LOCALES, true) ? $base : null;
    }

    /**
     * Pick a string out of a `['en' => …, 'ru' => …]` map.
     *
     * @param  array<string, string>  $strings
     * @param  array<string, string|int>  $replace
     */
    public static function pick(array $strings, ?string $locale, array $replace = []): string
    {
        $locale = self::normalise($locale) ?? 'en';
        $text = $strings[$locale] ?? $strings['en'] ?? '';

        foreach ($replace as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }
}
