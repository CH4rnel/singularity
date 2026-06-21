<?php

namespace App\Services;

use ActivityPhp\Server;
use ActivityPhp\Server\Http\Request;
use ActivityPhp\Server\Http\WebFingerFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Resolves a Fediverse handle (`user@instance.tld`) or a profile URL to its
 * ActivityPub actor via WebFinger discovery, using the landrok/activitypub
 * library. The raw ActivityStreams object is normalized into a flat, frontend
 * friendly shape; remote HTML (e.g. the actor summary) is stripped here so the
 * Inertia page never has to render untrusted markup.
 */
class FediverseLookup
{
    /**
     * Resolve a handle or profile URL to a normalized actor.
     *
     * @return array{actor: array<string,mixed>, raw: array<string,mixed>|null, posts: array<int,array<string,mixed>>}
     *
     * @throws InvalidArgumentException when the input is not a handle or URL
     * @throws Throwable when discovery or fetching fails
     */
    public function resolve(string $input): array
    {
        $input = trim($input);
        $isUrl = (bool) preg_match('#^https?://#i', $input);
        $handle = ltrim($input, '@');

        if (! $isUrl && ! preg_match('/^[\w.\-]+@[\w.\-]+(:\d+)?$/', $handle)) {
            throw new InvalidArgumentException(
                'Enter a handle like user@instance.tld or a full profile URL.'
            );
        }

        $server = new Server([
            // Our own host, so looked-up Fediverse instances are never mistaken
            // for a "local" actor by the library's resolver.
            'instance' => ['host' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'],
            'http' => ['timeout' => 8, 'agent' => 'Cyberia/1.0 (+https://cyberia.church)'],
            'cache' => ['stream' => storage_path('framework/cache/activitypub')],
        ]);

        // Resolve the canonical actor id via WebFinger first. WebFinger is
        // almost always served unsigned, so even an instance running in
        // "authorized fetch" (secure) mode still gives us the actor id and
        // profile link here — letting us degrade gracefully below.
        $webfinger = null;
        $profileId = $input;

        if (! $isUrl) {
            WebFingerFactory::setServer($server);
            $finger = WebFingerFactory::get($handle, 'https');
            $webfinger = $finger->toArray();
            $profileId = $finger->getProfileId() ?: $profileId;
        }

        try {
            $raw = $this->fetchActor($profileId, $server);
        } catch (Throwable $e) {
            if (! $this->isAuthorizedFetchError($e)) {
                throw $e;
            }

            // The instance only serves the actor to HTTP-signed requests.
            // We resolved it via WebFinger, so surface that partial profile.
            return [
                'actor' => $this->restrictedActor($handle, $isUrl, $profileId, $webfinger),
                'raw' => null,
                'posts' => $this->fetchPosts(null, $profileId, $server),
            ];
        }

        return [
            'actor' => $this->normalize($raw, $webfinger),
            'raw' => $raw,
            'posts' => $this->fetchPosts(
                is_string($raw['outbox'] ?? null) ? $raw['outbox'] : null,
                is_string($raw['id'] ?? null) ? $raw['id'] : $profileId,
                $server
            ),
        ];
    }

    /**
     * Best-effort fetch of an actor's most recent posts. Tries the ActivityPub
     * outbox first, then falls back to the actor's RSS feed — which secure-mode
     * (authorized-fetch) instances still serve unsigned even when the outbox is
     * locked. Any failure just yields an empty feed.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchPosts(?string $outboxUrl, ?string $actorId, Server $server, int $limit = 10): array
    {
        $posts = $this->fetchOutboxPosts($outboxUrl, $server, $limit);

        if ($posts === [] && $actorId !== null && $actorId !== '') {
            $posts = $this->fetchPostsFromRss($actorId, $server, $limit);
        }

        return $posts;
    }

    /**
     * Fetch recent posts from an ActivityPub outbox collection.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchOutboxPosts(?string $outboxUrl, Server $server, int $limit): array
    {
        if ($outboxUrl === null || $outboxUrl === '') {
            return [];
        }

        try {
            $request = new Request(
                (float) $server->config('http.timeout'),
                (string) $server->config('http.agent')
            );

            $outbox = json_decode($request->get($outboxUrl), true);

            // The outbox is an OrderedCollection; items live on its first page
            // (a URL or an inline object), or occasionally inline on the outbox.
            $page = match (true) {
                isset($outbox['orderedItems']) => $outbox,
                is_array($outbox['first'] ?? null) => $outbox['first'],
                is_string($outbox['first'] ?? null) => json_decode($request->get($outbox['first']), true),
                default => null,
            };

            if (! is_array($page)) {
                return [];
            }

            $posts = [];

            foreach ($page['orderedItems'] ?? [] as $item) {
                $post = is_array($item) ? $this->normalizePost($item) : null;

                if ($post !== null) {
                    $posts[] = $post;

                    if (count($posts) >= $limit) {
                        break;
                    }
                }
            }

            return $posts;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Fetch and parse an actor's RSS feed (Mastodon/Pleroma serve it at the
     * actor id + ".rss", unsigned, even in authorized-fetch mode).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchPostsFromRss(string $actorId, Server $server, int $limit): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) $server->config('http.agent'),
                'Accept' => 'application/rss+xml, application/xml, text/xml',
            ])
                ->timeout((int) $server->config('http.timeout'))
                ->get(rtrim($actorId, '/').'.rss');

            return $response->ok() ? $this->postsFromRss($response->body(), $limit) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Parse posts out of an RSS feed body. Pure (no I/O) so it can be tested.
     *
     * @return array<int,array<string,mixed>>
     */
    public function postsFromRss(string $xml, int $limit = 10): array
    {
        $feed = @simplexml_load_string($xml);

        if ($feed === false || ! isset($feed->channel->item)) {
            return [];
        }

        $posts = [];

        foreach ($feed->channel->item as $item) {
            $images = [];

            // <media:content url="…" medium="image" type="image/…">
            foreach ($item->children('http://search.yahoo.com/mrss/')->content as $media) {
                $attributes = $media->attributes();
                $url = (string) ($attributes['url'] ?? '');

                if ($url !== ''
                    && ((string) ($attributes['medium'] ?? '') === 'image'
                        || str_starts_with((string) ($attributes['type'] ?? ''), 'image/'))
                ) {
                    $images[] = $url;
                }
            }

            // <enclosure url="…" type="image/…">
            foreach ($item->enclosure as $enclosure) {
                $attributes = $enclosure->attributes();
                $url = (string) ($attributes['url'] ?? '');

                if ($url !== '' && str_starts_with((string) ($attributes['type'] ?? ''), 'image/')) {
                    $images[] = $url;
                }
            }

            $link = (string) $item->link !== '' ? (string) $item->link : (string) $item->guid;
            $content = trim(html_entity_decode(strip_tags((string) $item->description), ENT_QUOTES | ENT_HTML5));

            $posts[] = [
                'id' => (string) $item->guid !== '' ? (string) $item->guid : ($link ?: null),
                'url' => $link !== '' ? $link : null,
                'published' => $this->normalizeDate((string) $item->pubDate),
                'content' => $content !== '' ? $content : null,
                'images' => array_values(array_unique($images)),
                'sensitive' => false,
            ];

            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    /**
     * Normalize an RSS RFC-822 date to ISO-8601 so the feed reads consistently
     * with ActivityPub timestamps. Falls back to the raw value if unparseable.
     */
    private function normalizeDate(string $date): ?string
    {
        if (trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toIso8601String();
        } catch (Throwable) {
            return $date;
        }
    }

    /**
     * Normalize one outbox activity into a renderable post, or null for items
     * we don't show (boosts, likes, deletes, replies to others).
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>|null
     */
    public function normalizePost(array $item): ?array
    {
        // Unwrap `Create` activities to their inline object; skip everything
        // whose object is a bare URL (e.g. `Announce` boosts).
        $object = ($item['type'] ?? null) === 'Create' && is_array($item['object'] ?? null)
            ? $item['object']
            : $item;

        if (! in_array($object['type'] ?? null, ['Note', 'Article'], true)) {
            return null;
        }

        $content = isset($object['content'])
            ? trim(html_entity_decode(strip_tags((string) $object['content']), ENT_QUOTES | ENT_HTML5))
            : null;

        $images = [];

        foreach ($object['attachment'] ?? [] as $attachment) {
            if (is_array($attachment)
                && str_starts_with((string) ($attachment['mediaType'] ?? ''), 'image/')
                && isset($attachment['url']) && is_string($attachment['url'])
            ) {
                $images[] = $attachment['url'];
            }
        }

        return [
            'id' => is_string($object['id'] ?? null) ? $object['id'] : null,
            'url' => $this->firstUrl($object['url'] ?? ($object['id'] ?? null)),
            'published' => $object['published'] ?? null,
            'content' => $content !== '' ? $content : null,
            'images' => $images,
            'sensitive' => (bool) ($object['sensitive'] ?? false),
        ];
    }

    /**
     * Fetch the actor's raw ActivityStreams JSON using landrok's HTTP
     * transport (honoring its user-agent, timeout and cache), but decode it
     * ourselves. landrok's strict Type layer rejects the extension properties
     * real Mastodon/Lemmy/Pixelfed actors carry, so we skip it.
     *
     * @return array<string,mixed>
     *
     * @throws Throwable on an HTTP error (e.g. 401 from authorized fetch)
     */
    private function fetchActor(string $url, Server $server): array
    {
        $json = (new Request(
            (float) $server->config('http.timeout'),
            (string) $server->config('http.agent')
        ))->get($url);

        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['type'], $data['id'])) {
            throw new RuntimeException('That URL did not return an ActivityPub actor.');
        }

        return $data;
    }

    /**
     * An instance in "authorized fetch" / secure mode rejects unsigned actor
     * requests with a 401/403. landrok rethrows the Guzzle error as a plain
     * exception, so we match on its message.
     */
    private function isAuthorizedFetchError(Throwable $e): bool
    {
        return (bool) preg_match(
            '/\b40[13]\b|unauthorized|forbidden|request not signed/i',
            $e->getMessage()
        );
    }

    /**
     * Build a partial actor from WebFinger alone, for instances that won't
     * serve the full actor object without a signed request.
     *
     * @param  array<string,mixed>|null  $webfinger
     * @return array<string,mixed>
     */
    private function restrictedActor(string $handle, bool $isUrl, string $profileId, ?array $webfinger): array
    {
        $username = null;

        if (! $isUrl) {
            $username = explode('@', $handle)[0];
        } elseif (preg_match('#/([\w.\-]+)/?$#', $profileId, $matches)) {
            $username = $matches[1];
        }

        return array_merge($this->normalize([], $webfinger), [
            'id' => $profileId,
            'username' => $username,
            'name' => $username,
            'url' => $this->webfingerProfileUrl($webfinger) ?? $profileId,
            'restricted' => true,
        ]);
    }

    /**
     * Pull the human-facing profile page URL out of a WebFinger record.
     *
     * @param  array<string,mixed>|null  $webfinger
     */
    private function webfingerProfileUrl(?array $webfinger): ?string
    {
        foreach ($webfinger['links'] ?? [] as $link) {
            if (($link['rel'] ?? null) === 'http://webfinger.net/rel/profile-page'
                && isset($link['href']) && is_string($link['href'])
            ) {
                return $link['href'];
            }
        }

        return null;
    }

    /**
     * Flatten an ActivityStreams actor (and optional WebFinger record) into the
     * shape the page renders.
     *
     * @param  array<string,mixed>  $actor
     * @param  array<string,mixed>|null  $webfinger
     * @return array<string,mixed>
     */
    public function normalize(array $actor, ?array $webfinger = null): array
    {
        return [
            'id' => $actor['id'] ?? null,
            'type' => $actor['type'] ?? null,
            'username' => $actor['preferredUsername'] ?? null,
            'name' => $actor['name'] ?? ($actor['preferredUsername'] ?? null),
            'summary' => isset($actor['summary'])
                ? trim(strip_tags((string) $actor['summary']))
                : null,
            'url' => $this->firstUrl($actor['url'] ?? ($actor['id'] ?? null)),
            'icon' => $this->mediaUrl($actor['icon'] ?? null),
            'image' => $this->mediaUrl($actor['image'] ?? null),
            'inbox' => $actor['inbox'] ?? null,
            'outbox' => $actor['outbox'] ?? null,
            'followers' => $actor['followers'] ?? null,
            'following' => $actor['following'] ?? null,
            'manuallyApprovesFollowers' => $actor['manuallyApprovesFollowers'] ?? null,
            'published' => $actor['published'] ?? null,
            'publicKeyPem' => isset($actor['publicKey']['publicKeyPem'])
                ? trim((string) $actor['publicKey']['publicKeyPem'])
                : null,
            'webfinger' => $webfinger ? [
                'subject' => $webfinger['subject'] ?? null,
                'aliases' => $webfinger['aliases'] ?? [],
            ] : null,
            'restricted' => false,
        ];
    }

    /**
     * The `url` property may be a string, a single Link object, or a list of
     * either. Return the first usable href.
     */
    private function firstUrl(mixed $url): ?string
    {
        if (is_string($url)) {
            return $url;
        }

        if (is_array($url)) {
            if (isset($url['href']) && is_string($url['href'])) {
                return $url['href'];
            }

            foreach ($url as $candidate) {
                if (is_string($candidate)) {
                    return $candidate;
                }

                if (is_array($candidate) && isset($candidate['href']) && is_string($candidate['href'])) {
                    return $candidate['href'];
                }
            }
        }

        return null;
    }

    /**
     * Extract a URL from an `icon`/`image` value, which may be a string, an
     * Image object with a `url`, or a list of those.
     */
    private function mediaUrl(mixed $media): ?string
    {
        if (is_string($media)) {
            return $media;
        }

        if (is_array($media)) {
            if (isset($media['url'])) {
                return is_string($media['url']) ? $media['url'] : $this->firstUrl($media['url']);
            }

            foreach ($media as $candidate) {
                $url = $this->mediaUrl($candidate);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }
}
