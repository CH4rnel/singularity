<?php

use App\Services\FediverseLookup;

it('normalizes a mastodon-style actor and strips summary html', function () {
    $actor = (new FediverseLookup)->normalize([
        'id' => 'https://mastodon.social/users/Gargron',
        'type' => 'Person',
        'preferredUsername' => 'Gargron',
        'name' => 'Eugen Rochko',
        'summary' => '<p>Founder of <a href="https://joinmastodon.org">Mastodon</a></p>',
        'url' => 'https://mastodon.social/@Gargron',
        'manuallyApprovesFollowers' => false,
        'inbox' => 'https://mastodon.social/users/Gargron/inbox',
        'outbox' => 'https://mastodon.social/users/Gargron/outbox',
        'followers' => 'https://mastodon.social/users/Gargron/followers',
        'icon' => ['type' => 'Image', 'url' => 'https://files.example/avatar.png'],
        'image' => 'https://files.example/header.png',
        'publicKey' => ['publicKeyPem' => "-----BEGIN PUBLIC KEY-----\nABC\n-----END PUBLIC KEY-----"],
    ], [
        'subject' => 'acct:Gargron@mastodon.social',
        'aliases' => ['https://mastodon.social/@Gargron'],
    ]);

    expect($actor['username'])->toBe('Gargron')
        ->and($actor['name'])->toBe('Eugen Rochko')
        ->and($actor['summary'])->toBe('Founder of Mastodon')
        ->and($actor['url'])->toBe('https://mastodon.social/@Gargron')
        ->and($actor['icon'])->toBe('https://files.example/avatar.png')
        ->and($actor['image'])->toBe('https://files.example/header.png')
        ->and($actor['publicKeyPem'])->toContain('BEGIN PUBLIC KEY')
        ->and($actor['webfinger']['subject'])->toBe('acct:Gargron@mastodon.social')
        ->and($actor['restricted'])->toBeFalse();
});

it('falls back to the username and tolerates a sparse actor', function () {
    $actor = (new FediverseLookup)->normalize([
        'id' => 'https://example.com/users/bob',
        'type' => 'Service',
        'preferredUsername' => 'bob',
    ]);

    expect($actor['name'])->toBe('bob')
        ->and($actor['summary'])->toBeNull()
        ->and($actor['icon'])->toBeNull()
        ->and($actor['publicKeyPem'])->toBeNull()
        ->and($actor['webfinger'])->toBeNull();
});

it('resolves a url from a list of link objects', function () {
    $actor = (new FediverseLookup)->normalize([
        'id' => 'https://example.com/users/alice',
        'preferredUsername' => 'alice',
        'url' => [
            ['type' => 'Link', 'href' => 'https://example.com/@alice'],
        ],
    ]);

    expect($actor['url'])->toBe('https://example.com/@alice');
});

it('normalizes a Create activity into a post with stripped content and images', function () {
    $post = (new FediverseLookup)->normalizePost([
        'type' => 'Create',
        'object' => [
            'id' => 'https://example.com/notes/1',
            'type' => 'Note',
            'url' => 'https://example.com/@alice/1',
            'published' => '2026-01-02T03:04:05Z',
            'content' => '<p>Hello <a href="#">world</a> &amp; friends</p>',
            'sensitive' => false,
            'attachment' => [
                ['type' => 'Document', 'mediaType' => 'image/png', 'url' => 'https://files.example/1.png'],
                ['type' => 'Document', 'mediaType' => 'video/mp4', 'url' => 'https://files.example/clip.mp4'],
            ],
        ],
    ]);

    expect($post)->not->toBeNull()
        ->and($post['content'])->toBe('Hello world & friends')
        ->and($post['url'])->toBe('https://example.com/@alice/1')
        ->and($post['published'])->toBe('2026-01-02T03:04:05Z')
        ->and($post['images'])->toBe(['https://files.example/1.png']);
});

it('skips boosts and non-post activities', function () {
    $service = new FediverseLookup;

    expect($service->normalizePost(['type' => 'Announce', 'object' => 'https://example.com/notes/9']))->toBeNull()
        ->and($service->normalizePost(['type' => 'Like', 'object' => 'https://example.com/notes/9']))->toBeNull();
});

it('parses posts from an RSS feed including media images', function () {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
      <channel>
        <title>OpenNet</title>
        <item>
          <guid isPermaLink="true">https://zhub.link/@opennet/1</guid>
          <link>https://zhub.link/@opennet/1</link>
          <pubDate>Wed, 11 Jun 2025 10:00:00 +0000</pubDate>
          <description>&lt;p&gt;Hello &amp;amp; welcome&lt;/p&gt;</description>
          <media:content url="https://files.example/a.png" type="image/png" medium="image"/>
        </item>
        <item>
          <link>https://zhub.link/@opennet/2</link>
          <description>&lt;p&gt;Second&lt;/p&gt;</description>
        </item>
      </channel>
    </rss>
    XML;

    $posts = (new FediverseLookup)->postsFromRss($xml);

    expect($posts)->toHaveCount(2)
        ->and($posts[0]['content'])->toBe('Hello & welcome')
        ->and($posts[0]['url'])->toBe('https://zhub.link/@opennet/1')
        ->and($posts[0]['published'])->toStartWith('2025-06-11T10:00:00')
        ->and($posts[0]['images'])->toBe(['https://files.example/a.png'])
        ->and($posts[1]['images'])->toBe([]);
});
