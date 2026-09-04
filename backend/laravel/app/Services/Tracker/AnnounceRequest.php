<?php

namespace App\Services\Tracker;

/**
 * One announce, parsed.
 *
 * The parsing is the interesting half. An announce carries two twenty-byte
 * binary values — the info hash and the peer id — percent-encoded into a query
 * string, and PHP's own `$_GET` is not a safe place to read them from: the
 * framework trims strings, converts empty ones to null, and a hash whose first
 * byte happens to be 0x20 or 0x0a comes out one byte short and matches
 * nothing. So this reads the raw `QUERY_STRING` and decodes it here, where
 * bytes stay bytes.
 *
 * Hex is the storage form throughout, converted once at this boundary: the
 * database, the URLs and every log line then carry the same forty characters
 * every other client in the world already prints.
 */
final readonly class AnnounceRequest
{
    public function __construct(
        /** Lowercase hex, 40 characters. */
        public string $infoHash,
        /** Lowercase hex of the client's own id, up to 40 characters. */
        public string $peerId,
        public string $ip,
        public int $port,
        public int $uploaded,
        public int $downloaded,
        public int $left,
        /** '', `started`, `stopped` or `completed`. */
        public string $event,
        public bool $compact,
        public int $numwant,
    ) {}

    /**
     * Pairs from a raw query string, in order and with duplicates kept.
     *
     * `rawurldecode` and not `urldecode`: a `+` in a percent-encoded info hash
     * is the byte 0x2B, and reading it as a space corrupts one hash in every
     * few hundred. Duplicates are kept because `scrape` legitimately repeats
     * `info_hash`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(string $queryString): array
    {
        $pairs = [];

        foreach (explode('&', $queryString) as $chunk) {
            if ($chunk === '') {
                continue;
            }

            $split = explode('=', $chunk, 2);

            $pairs[] = [rawurldecode($split[0]), rawurldecode($split[1] ?? '')];
        }

        return $pairs;
    }

    /**
     * The first value for a key, or null. Announce parameters are single
     * valued; a client repeating one is answered from its first.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public static function first(array $pairs, string $key): ?string
    {
        foreach ($pairs as [$name, $value]) {
            if ($name === $key) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     *
     * @throws TrackerFailure
     */
    public static function fromPairs(array $pairs, string $remoteIp): self
    {
        $infoHash = self::first($pairs, 'info_hash') ?? '';

        if (strlen($infoHash) !== 20) {
            throw new TrackerFailure('info_hash must be exactly 20 bytes');
        }

        $peerId = self::first($pairs, 'peer_id') ?? '';

        // Twenty bytes is what the specification says and what every client
        // sends; a shorter one is still a usable identity and refusing it
        // would drop a real peer over a formality.
        if ($peerId === '' || strlen($peerId) > 20) {
            throw new TrackerFailure('peer_id must be 1 to 20 bytes');
        }

        $port = (int) (self::first($pairs, 'port') ?? 0);

        if ($port < 1 || $port > 65535) {
            throw new TrackerFailure('port is not a port');
        }

        $event = strtolower(trim(self::first($pairs, 'event') ?? ''));

        if (! in_array($event, ['', 'started', 'stopped', 'completed', 'empty'], true)) {
            throw new TrackerFailure('unknown event');
        }

        $numwant = self::first($pairs, 'numwant');
        $max = (int) config('tracker.max_numwant', 200);

        return new self(
            infoHash: bin2hex($infoHash),
            peerId: bin2hex($peerId),
            ip: $remoteIp,
            port: $port,
            uploaded: max(0, (int) (self::first($pairs, 'uploaded') ?? 0)),
            downloaded: max(0, (int) (self::first($pairs, 'downloaded') ?? 0)),
            left: max(0, (int) (self::first($pairs, 'left') ?? 0)),
            event: $event === 'empty' ? '' : $event,
            // Compact is the default: the dictionary form is six times the
            // bytes and only old clients ask for it, so it is opt-out.
            compact: (self::first($pairs, 'compact') ?? '1') !== '0',
            numwant: $numwant === null
                ? (int) config('tracker.numwant', 50)
                : max(0, min($max, (int) $numwant)),
        );
    }
}
