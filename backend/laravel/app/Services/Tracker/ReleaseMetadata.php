<?php

namespace App\Services\Tracker;

/**
 * What a token's metadata says the release is.
 *
 * This is the whole contract between a minter and the index, and it is pure on
 * purpose: given the JSON document a CID names, it produces the row or it
 * explains what is missing, with no chain and no network in the way.
 *
 * The document is ordinary ERC-721 metadata with one extra key. A marketplace
 * that has never heard of this tracker still renders the name, the description
 * and the cover; this reads the `torrent` object beside them, and falls back to
 * the standard `attributes` list when a token was minted by something that
 * only knew how to write those.
 */
final class ReleaseMetadata
{
    /** Extensions that decide what a release *is*, for the player's sake. */
    private const VIDEO = ['mp4', 'mkv', 'webm', 'avi', 'mov', 'm4v', 'mpg', 'mpeg', 'wmv', 'flv', 'ogv', 'ts'];

    private const AUDIO = ['mp3', 'flac', 'wav', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'wma', 'aiff', 'alac', 'ape'];

    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'tif', 'tiff'];

    private const TEXT = ['pdf', 'epub', 'fb2', 'djvu', 'txt', 'mobi', 'azw3', 'cbz', 'cbr'];

    /**
     * The release, as fields ready for the model.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     *
     * @throws RegistrationFailed
     */
    public static function parse(array $document): array
    {
        $torrent = is_array($document['torrent'] ?? null) ? $document['torrent'] : [];
        $attributes = self::attributes($document);

        $infoHash = strtolower(trim((string) (
            $torrent['info_hash']
            ?? $torrent['infoHash']
            ?? $attributes['info hash']
            ?? $attributes['infohash']
            ?? ''
        )));

        if (! preg_match('/^[0-9a-f]{40}$/', $infoHash)) {
            throw new RegistrationFailed(
                'This token does not name a torrent: its metadata carries no 40-character info hash.',
            );
        }

        $files = self::files($torrent);

        $name = self::text($torrent['name'] ?? $document['name'] ?? '', 200);

        if ($name === '') {
            throw new RegistrationFailed('This release has no name.');
        }

        $length = self::size($torrent, $files);

        return [
            'info_hash' => $infoHash,
            'name' => $name,
            'description' => self::text($document['description'] ?? '', 4000),
            'category' => self::category($torrent['category'] ?? $attributes['category'] ?? null, $files),
            'size_bytes' => $length,
            'file_count' => count($files) > 0 ? count($files) : 1,
            'files' => $files,
            'magnet' => self::magnet($torrent['magnet'] ?? null, $infoHash, $name),
            'media_kind' => self::mediaKind($files),
            // Something to play without joining the swarm, when the minter
            // pinned one. `animation_url` is where every marketplace already
            // looks for a token's media, so a preview is visible outside this
            // index too rather than only here.
            'preview_url' => self::link($torrent['preview'] ?? $document['animation_url'] ?? null),
            'cover_url' => self::link($document['image'] ?? null),
        ];
    }

    /**
     * What this release mostly is, from the files' own names.
     *
     * The player asks this question and nothing else does: a release of forty
     * FLACs is an album and opens as a playlist, one MKV is a film and opens
     * as a video. A release with both is `mixed`, which the player reads as
     * "let the person choose" rather than guessing wrong twice.
     *
     * @param  list<array{path: string, length: int}>  $files
     */
    public static function mediaKind(array $files): string
    {
        $video = 0;
        $audio = 0;

        foreach ($files as $file) {
            $extension = self::extension($file['path']);

            if (in_array($extension, self::VIDEO, true)) {
                $video++;
            } elseif (in_array($extension, self::AUDIO, true)) {
                $audio++;
            }
        }

        if ($video > 0 && $audio > 0) {
            return 'mixed';
        }

        if ($video > 0) {
            return 'video';
        }

        return $audio > 0 ? 'audio' : 'other';
    }

    /**
     * Which shelf this goes on.
     *
     * A declared category wins when it is one this index has; otherwise the
     * files decide. Nothing is refused for being filed wrong — the token is
     * already minted by the time anyone reads this, and losing a release over
     * a misspelt word would be the worst available outcome.
     *
     * @param  list<array{path: string, length: int}>  $files
     */
    public static function category(mixed $declared, array $files): string
    {
        $allowed = (array) config('tracker.categories', ['other']);
        $value = strtolower(trim((string) $declared));

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $media = self::mediaKind($files);

        if ($media === 'video' || $media === 'mixed') {
            return 'video';
        }

        if ($media === 'audio') {
            return 'audio';
        }

        foreach ($files as $file) {
            $extension = self::extension($file['path']);

            if (in_array($extension, self::IMAGE, true)) {
                return 'image';
            }

            if (in_array($extension, self::TEXT, true)) {
                return 'text';
            }
        }

        return 'other';
    }

    /**
     * The file list, sanitised.
     *
     * Paths come from a stranger's torrent and are only ever displayed, so
     * they are stripped of control characters and of anything that would let a
     * name climb out of its own row — `..` segments and leading slashes are
     * flattened here rather than trusted anywhere later.
     *
     * @param  array<string, mixed>  $torrent
     * @return list<array{path: string, length: int}>
     */
    private static function files(array $torrent): array
    {
        $raw = is_array($torrent['files'] ?? null) ? $torrent['files'] : [];
        $limit = (int) config('tracker.metadata.max_files', 2000);
        $files = [];

        foreach ($raw as $entry) {
            if (count($files) >= $limit) {
                break;
            }

            if (! is_array($entry)) {
                continue;
            }

            $path = self::path((string) ($entry['path'] ?? $entry['name'] ?? ''));

            if ($path === '') {
                continue;
            }

            $files[] = ['path' => $path, 'length' => max(0, (int) ($entry['length'] ?? 0))];
        }

        return $files;
    }

    private static function path(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '';
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $clean)) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }

            $segments[] = $segment;
        }

        return mb_substr(implode('/', $segments), 0, 400);
    }

    /**
     * @param  array<string, mixed>  $torrent
     * @param  list<array{path: string, length: int}>  $files
     */
    private static function size(array $torrent, array $files): int
    {
        $declared = (int) ($torrent['length'] ?? $torrent['size'] ?? 0);

        if ($declared > 0) {
            return $declared;
        }

        return (int) array_sum(array_column($files, 'length'));
    }

    /**
     * The magnet link, checked against the hash rather than believed.
     *
     * A magnet naming a different torrent than the token does is the one
     * mismatch that matters here — it is how a release could show one thing
     * and hand out another — so it is discarded and rebuilt from the info hash
     * this row is keyed by. The tracker is added because a swarm that only
     * exists in the DHT takes minutes to find.
     */
    private static function magnet(mixed $declared, string $infoHash, string $name): string
    {
        $value = trim((string) $declared);

        if ($value !== '' && stripos($value, 'magnet:?') === 0
            && stripos($value, 'urn:btih:'.$infoHash) !== false) {
            return mb_substr($value, 0, 2000);
        }

        return 'magnet:?xt=urn:btih:'.$infoHash
            .'&dn='.rawurlencode($name)
            .'&tr='.rawurlencode((string) config('tracker.announce_url'));
    }

    /** An https or ipfs link, or null. Nothing else is stored to be rendered. */
    private static function link(mixed $value): ?string
    {
        $link = trim((string) $value);

        if ($link === '' || mb_strlen($link) > 500) {
            return null;
        }

        return preg_match('#^(https://|ipfs://)#i', $link) === 1 ? $link : null;
    }

    /**
     * Standard ERC-721 attributes, lowercased by trait name.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, string>
     */
    private static function attributes(array $document): array
    {
        $pairs = [];

        foreach ((array) ($document['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute) || ! isset($attribute['trait_type'])) {
                continue;
            }

            $pairs[strtolower(trim((string) $attribute['trait_type']))] = (string) ($attribute['value'] ?? '');
        }

        return $pairs;
    }

    private static function text(mixed $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) $value) ?? '';

        return mb_substr(trim($clean), 0, $limit);
    }

    private static function extension(string $path): string
    {
        $dot = strrpos($path, '.');

        return $dot === false ? '' : strtolower(substr($path, $dot + 1));
    }
}
