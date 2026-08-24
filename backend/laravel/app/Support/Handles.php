<?php

namespace App\Support;

/**
 * What an operator actually types when asked for a handle.
 *
 * Nobody transcribes `lain` out of a profile they are looking at — they copy
 * the address bar, or they type the `@` because that is how the name is
 * written everywhere else. Three spellings of one handle in a column means the
 * link built from it works two times in three, so the shapes are collapsed
 * here, once, on the way in: URL, `@name` and `name` all store `name`.
 *
 * The reverse direction lives here too, because the same rule decides whether
 * a stored value can become a link at all: the CRM sync writes numeric
 * Telegram *ids* into the same column (that is all the bot knows about
 * somebody who never set a username), and `t.me/812…` is a dead address. A
 * value that cannot be written to is not offered as a way of writing.
 */
class Handles
{
    /**
     * A Telegram handle out of anything that names one.
     *
     * Kept permissive on purpose — this column also holds numeric ids put
     * there by the sync, and refusing them here would make an unrelated
     * record unsavable the first time somebody edited its name.
     */
    public static function telegram(?string $value): ?string
    {
        $value = self::bare($value, ['t.me', 'telegram.me', 'telegram.dog']);

        return $value === '' ? null : $value;
    }

    /**
     * An X handle out of anything that names one.
     *
     * Twitter's own hosts are read as well as x.com: the accounts found today
     * are linked from posts written years ago.
     */
    public static function x(?string $value): ?string
    {
        $value = self::bare($value, ['x.com', 'twitter.com', 'mobile.twitter.com']);

        return $value === '' ? null : $value;
    }

    /** The username reachable at `t.me/…`, or null when the column holds an id. */
    public static function telegramUsername(?string $value): ?string
    {
        $handle = self::telegram($value);

        // Telegram usernames must start with a letter, so an all-digit value
        // is an account id and nothing else.
        return $handle !== null && preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $handle) === 1
            ? $handle
            : null;
    }

    public static function telegramUrl(?string $value): ?string
    {
        $handle = self::telegramUsername($value);

        return $handle === null ? null : 'https://t.me/'.$handle;
    }

    public static function xUrl(?string $value): ?string
    {
        $handle = self::x($value);

        return $handle === null ? null : 'https://x.com/'.$handle;
    }

    /**
     * The same collapsing, applied to something being searched for.
     *
     * An operator looking for somebody types what they are looking at: the
     * `@` is part of how the name is written, and half the time the thing in
     * the clipboard is the whole profile URL. Both are stored bare, so both
     * have to be searched bare — a search box that answers "not found" for
     * `@lain` when `lain` is on the books is a search box that gets somebody
     * entered twice.
     */
    public static function searchable(?string $value): ?string
    {
        $bare = self::bare($value, [
            't.me', 'telegram.me', 'telegram.dog',
            'x.com', 'twitter.com', 'mobile.twitter.com',
        ]);

        return $bare === '' ? null : $bare;
    }

    /**
     * Strip a profile URL, a leading `@` and the surrounding whitespace.
     *
     * @param  array<int, string>  $hosts  hosts whose first path segment is the handle
     */
    private static function bare(?string $value, array $hosts): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^(?:https?://)?(?:www\.)?([^/\s]+)/(.+)$#i', $value, $matches) === 1
            && in_array(strtolower($matches[1]), $hosts, true)) {
            // `x.com/lain/status/…` and `x.com/lain?s=20` both name `lain`.
            $value = explode('/', explode('?', $matches[2])[0])[0];
        }

        return ltrim(trim($value), '@');
    }
}
