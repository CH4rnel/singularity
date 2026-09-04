<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Bencode — the encoding BitTorrent speaks.
 *
 * A tracker has no choice about this: `.torrent` files and every announce
 * response are bencoded, and a client that gets JSON back simply reports that
 * the tracker is broken. Four types and no more: integers, byte strings,
 * lists, dictionaries.
 *
 * Two properties are load-bearing rather than stylistic. Strings are **bytes**,
 * not text — an info hash is twenty arbitrary bytes and a filename may be any
 * encoding at all, so nothing here validates UTF-8 or trims anything. And a
 * dictionary's keys are emitted in bytewise sorted order, because the info
 * hash of a torrent is SHA-1 over its bencoded info dictionary: two encoders
 * that disagree about key order produce two different torrents from one file.
 */
final class Bencode
{
    /**
     * A PHP value as bencode.
     *
     * A list array becomes a list, an associative one a dictionary; booleans
     * become 1/0 because bencode has no boolean and every client reads the
     * integer. Null is refused rather than encoded as an empty string — a
     * field that has no value belongs out of the dictionary, and silently
     * writing "" is how a tracker answers with a peer whose id is nothing.
     */
    public static function encode(mixed $value): string
    {
        if (is_bool($value)) {
            return 'i'.($value ? '1' : '0').'e';
        }

        if (is_int($value)) {
            return 'i'.$value.'e';
        }

        if (is_string($value)) {
            return strlen($value).':'.$value;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return 'l'.implode('', array_map(self::encode(...), $value)).'e';
            }

            // Bytewise, not locale-aware: `ksort` with SORT_STRING is what
            // every other implementation does, and the hash depends on it.
            ksort($value, SORT_STRING);

            $out = 'd';

            foreach ($value as $key => $item) {
                $out .= self::encode((string) $key).self::encode($item);
            }

            return $out.'e';
        }

        throw new InvalidArgumentException('Bencode cannot carry '.get_debug_type($value));
    }

    /**
     * Bencode as PHP.
     *
     * Strict: trailing bytes after the top-level value are an error, not
     * something to ignore. This parses `.torrent` files uploaded by strangers,
     * so every length is checked against what is actually there before it is
     * used to slice.
     */
    public static function decode(string $input): mixed
    {
        $offset = 0;
        $value = self::read($input, $offset);

        if ($offset !== strlen($input)) {
            throw new InvalidArgumentException('Trailing bytes after the bencoded value');
        }

        return $value;
    }

    /**
     * The bencoded bytes of one key inside a dictionary, verbatim.
     *
     * This is what makes an info hash computable: SHA-1 is taken over the
     * *original* bytes of the info dictionary, and re-encoding a decoded one
     * only reproduces them when the file was written by an encoder that agreed
     * with this one about key order and integer formatting. Slicing the
     * original is exact, and it is the only way to hash a torrent nobody here
     * wrote.
     */
    public static function slice(string $input, string $key): ?string
    {
        $offset = 0;

        if (($input[0] ?? '') !== 'd') {
            return null;
        }

        $offset = 1;

        while (($input[$offset] ?? '') !== 'e') {
            if ($offset >= strlen($input)) {
                throw new InvalidArgumentException('Unterminated dictionary');
            }

            $name = self::read($input, $offset);
            $start = $offset;
            self::read($input, $offset);

            if ($name === $key) {
                return substr($input, $start, $offset - $start);
            }
        }

        return null;
    }

    private static function read(string $input, int &$offset): mixed
    {
        $marker = $input[$offset] ?? '';

        if ($marker === '') {
            throw new InvalidArgumentException('Bencode ended early');
        }

        if ($marker === 'i') {
            return self::readInteger($input, $offset);
        }

        if ($marker === 'l') {
            return self::readList($input, $offset);
        }

        if ($marker === 'd') {
            return self::readDictionary($input, $offset);
        }

        if (ctype_digit($marker)) {
            return self::readString($input, $offset);
        }

        throw new InvalidArgumentException("Unexpected byte '{$marker}' at offset {$offset}");
    }

    private static function readInteger(string $input, int &$offset): int
    {
        $end = strpos($input, 'e', $offset);

        if ($end === false) {
            throw new InvalidArgumentException('Unterminated integer');
        }

        $digits = substr($input, $offset + 1, $end - $offset - 1);

        // `i-0e` and leading zeros are invalid bencode. A file carrying them
        // was not written by a client, and the sizes inside it are not to be
        // trusted either.
        if (! preg_match('/^(0|-?[1-9][0-9]*)$/', $digits)) {
            throw new InvalidArgumentException("Malformed integer '{$digits}'");
        }

        $offset = $end + 1;

        return (int) $digits;
    }

    private static function readString(string $input, int &$offset): string
    {
        $colon = strpos($input, ':', $offset);

        if ($colon === false) {
            throw new InvalidArgumentException('String with no length');
        }

        $digits = substr($input, $offset, $colon - $offset);

        if (! preg_match('/^(0|[1-9][0-9]*)$/', $digits)) {
            throw new InvalidArgumentException("Malformed string length '{$digits}'");
        }

        $length = (int) $digits;

        if ($colon + 1 + $length > strlen($input)) {
            throw new InvalidArgumentException('String longer than the data it is in');
        }

        $offset = $colon + 1 + $length;

        return substr($input, $colon + 1, $length);
    }

    /** @return list<mixed> */
    private static function readList(string $input, int &$offset): array
    {
        $offset++;
        $items = [];

        while (($input[$offset] ?? '') !== 'e') {
            if ($offset >= strlen($input)) {
                throw new InvalidArgumentException('Unterminated list');
            }

            $items[] = self::read($input, $offset);
        }

        $offset++;

        return $items;
    }

    /** @return array<string, mixed> */
    private static function readDictionary(string $input, int &$offset): array
    {
        $offset++;
        $entries = [];

        while (($input[$offset] ?? '') !== 'e') {
            if ($offset >= strlen($input)) {
                throw new InvalidArgumentException('Unterminated dictionary');
            }

            $key = self::read($input, $offset);

            if (! is_string($key)) {
                throw new InvalidArgumentException('Dictionary key is not a string');
            }

            $entries[$key] = self::read($input, $offset);
        }

        $offset++;

        return $entries;
    }
}
