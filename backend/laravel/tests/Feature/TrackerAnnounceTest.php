<?php

use App\Models\TrackerPeer;
use App\Models\TrackerRelease;
use App\Support\Bencode;

/**
 * The tracker, spoken to the way a torrent client speaks to it.
 *
 * Every request here is built by hand rather than through `http_build_query`,
 * because that is the bug this endpoint exists to survive: an info hash is
 * twenty arbitrary bytes, `http_build_query` writes a space as `+`, and a
 * tracker that reads its hashes through the framework's string handling
 * silently drops every torrent whose hash starts with 0x20.
 */
function announceQuery(array $parameters): string
{
    $pairs = [];

    foreach ($parameters as $key => $value) {
        $pairs[] = rawurlencode($key).'='.rawurlencode((string) $value);
    }

    return implode('&', $pairs);
}

function trackedRelease(string $hex = '0a20005468697320697320612074657374212121'): TrackerRelease
{
    return TrackerRelease::create([
        'info_hash' => $hex,
        'name' => 'a release',
        'category' => 'audio',
        'size_bytes' => 1024,
        'file_count' => 1,
        'files' => [['path' => 'track.flac', 'length' => 1024]],
        'magnet' => 'magnet:?xt=urn:btih:'.$hex,
        'chain_id' => 49406,
        'contract' => '0x546462fabf30734e63b64f32b30ec8add9b6eba7',
        'token_id' => '7',
        'owner' => '0x0000000000000000000000000000000000000001',
        'token_uri' => 'ipfs://bafy/metadata.json',
        'media_kind' => 'audio',
    ]);
}

test('an unregistered swarm is refused in the only vocabulary a client reads', function () {
    $response = $this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin('1111111111111111111111111111111111111111'),
        'peer_id' => str_repeat('a', 20),
        'port' => 6881,
        'left' => 100,
    ]));

    $response->assertOk();

    $body = Bencode::decode($response->getContent());

    // A 200 with a failure reason, not a 404: a client that gets a 4xx reports
    // the tracker as down and never shows the sentence to anybody.
    expect($body)->toHaveKey('failure reason')
        ->and($body['failure reason'])->toContain('minted');
});

test('an announce joins the swarm and comes back with the peers already in it', function () {
    $release = trackedRelease();

    TrackerPeer::create([
        'info_hash' => $release->info_hash,
        'peer_id' => bin2hex(str_repeat('S', 20)),
        'ip' => '203.0.113.9',
        'port' => 51413,
        'left_bytes' => 0,
        'seeder' => true,
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
        'peer_id' => str_repeat('L', 20),
        'port' => 6881,
        'uploaded' => 0,
        'downloaded' => 0,
        'left' => 1024,
        'event' => 'started',
    ]));

    $body = Bencode::decode($response->getContent());

    expect($body['complete'])->toBe(1)
        ->and($body['incomplete'])->toBe(1)
        ->and($body['interval'])->toBe((int) config('tracker.interval'));

    // BEP 23: four bytes of address and two of port, big-endian, per peer —
    // and the announcing client is never handed itself.
    expect(strlen($body['peers']))->toBe(6)
        ->and(substr($body['peers'], 0, 4))->toBe(inet_pton('203.0.113.9'))
        ->and(unpack('n', substr($body['peers'], 4, 2))[1])->toBe(51413);

    expect(TrackerPeer::where('peer_id', bin2hex(str_repeat('L', 20)))->first())
        ->not->toBeNull();

    $release->refresh();

    expect($release->seeders)->toBe(1)->and($release->leechers)->toBe(1);
});

test('a hash whose first byte is whitespace still finds its swarm', function () {
    // 0x20 0x09 — a space and a tab. Trimmed input turns this into a hash that
    // is 18 bytes long and matches nothing on the index.
    $release = trackedRelease('2009000000000000000000000000000000000020');

    $response = $this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
        'peer_id' => str_repeat('P', 20),
        'port' => 6881,
        'left' => 0,
    ]));

    expect(Bencode::decode($response->getContent()))->toHaveKey('complete');
    expect(TrackerPeer::where('info_hash', $release->info_hash)->count())->toBe(1);
});

test('stopping leaves the swarm, and completing is counted once', function () {
    $release = trackedRelease();

    $announce = fn (string $event, int $left) => $this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
        'peer_id' => str_repeat('L', 20),
        'port' => 6881,
        'left' => $left,
        'event' => $event,
    ]));

    $announce('started', 1024);
    $announce('completed', 0);

    expect($release->refresh()->completed)->toBe(1)
        ->and($release->seeders)->toBe(1);

    $announce('stopped', 0);

    expect(TrackerPeer::where('info_hash', $release->info_hash)->count())->toBe(0)
        ->and($release->refresh()->seeders)->toBe(0);
});

test('a peer that stopped announcing is gone before it is handed to anybody', function () {
    $release = trackedRelease();

    TrackerPeer::create([
        'info_hash' => $release->info_hash,
        'peer_id' => bin2hex(str_repeat('D', 20)),
        'ip' => '203.0.113.4',
        'port' => 6881,
        'left_bytes' => 0,
        'seeder' => true,
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
        'peer_id' => str_repeat('N', 20),
        'port' => 6881,
        'left' => 500,
    ]));

    $body = Bencode::decode($response->getContent());

    expect($body['peers'])->toBe('')
        ->and($body['complete'])->toBe(0);
});

test('a client asking for no peers is answered with none', function () {
    $release = trackedRelease();

    TrackerPeer::create([
        'info_hash' => $release->info_hash,
        'peer_id' => bin2hex(str_repeat('S', 20)),
        'ip' => '203.0.113.9',
        'port' => 51413,
        'left_bytes' => 0,
        'seeder' => true,
        'expires_at' => now()->addHour(),
    ]);

    $body = Bencode::decode($this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
        'peer_id' => str_repeat('Z', 20),
        'port' => 6881,
        'left' => 10,
        'numwant' => 0,
    ]))->getContent());

    expect($body['peers'])->toBe('')->and($body['incomplete'])->toBe(1);
});

test('scrape answers for the hashes it was given, keyed by the raw bytes', function () {
    $release = trackedRelease();
    $release->forceFill(['seeders' => 3, 'leechers' => 2, 'completed' => 9])->save();

    $body = Bencode::decode($this->get('/scrape?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
    ]))->getContent());

    $key = hex2bin($release->info_hash);

    expect($body['files'])->toHaveKey($key)
        ->and($body['files'][$key])->toBe([
            'complete' => 3,
            'downloaded' => 9,
            'incomplete' => 2,
            'name' => 'a release',
        ]);
});

test('a hidden release is not on the tracker at all', function () {
    $release = trackedRelease();
    $release->forceFill(['hidden_at' => now()])->save();

    $body = Bencode::decode($this->get('/announce?'.announceQuery([
        'info_hash' => hex2bin($release->info_hash),
        'peer_id' => str_repeat('L', 20),
        'port' => 6881,
        'left' => 1,
    ]))->getContent());

    expect($body)->toHaveKey('failure reason');
    expect($this->get("/tracker/{$release->info_hash}")->status())->toBe(404);
});

test('a malformed announce is refused without touching the database', function () {
    $release = trackedRelease();

    foreach ([
        ['info_hash' => 'short', 'peer_id' => str_repeat('a', 20), 'port' => 6881],
        ['info_hash' => hex2bin($release->info_hash), 'peer_id' => str_repeat('a', 20), 'port' => 0],
        ['info_hash' => hex2bin($release->info_hash), 'peer_id' => '', 'port' => 6881],
    ] as $parameters) {
        $body = Bencode::decode($this->get('/announce?'.announceQuery($parameters))->getContent());

        expect($body)->toHaveKey('failure reason');
    }

    expect(TrackerPeer::count())->toBe(0);
});
