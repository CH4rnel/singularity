<?php

use App\Models\TrackerRelease;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use kornrunner\Keccak;

/**
 * Publishing on the tracker is minting, and this is where that is enforced.
 *
 * The submission is two fields — a chain and a token id — and everything the
 * index then shows was read by this server off the chain and out of the
 * document the token points at. So what these tests are really checking is
 * that there is no path by which a caller's own words end up on a release, and
 * that the three ways a token can fail to be one are told apart: the chain
 * does not know it, the document is not metadata, or the metadata describes no
 * torrent.
 */
function trackerAbiString(string $value): string
{
    $hex = bin2hex($value);

    return '0x'
        .str_pad('20', 64, '0', STR_PAD_LEFT)
        .str_pad(dechex(strlen($value)), 64, '0', STR_PAD_LEFT)
        .str_pad($hex, (int) (ceil(strlen($hex) / 64) * 64), '0', STR_PAD_RIGHT);
}

function trackerSelector(string $signature): string
{
    return '0x'.substr(Keccak::hash($signature, 256), 0, 8);
}

/** A chain that answers, and a gateway that serves one document. */
function fakeToken(array $metadata, string $owner = '0x00000000000000000000000000000000000000AA'): void
{
    config([
        'tracker.chains.49406.rpc_url' => 'https://rpc.test/',
        'tracker.chains.49406.collection' => '0x546462FAbf30734E63b64f32B30EC8ADD9B6EBa7',
        'ipfs.gateway' => 'https://gateway.test',
        // The stub reads the owner back out of config rather than closing over
        // it: `Http::fake` appends, so a second call in one test never
        // replaces the first stub and the chain has to be able to change its
        // mind — which is the whole of what a transfer looks like from here.
        'tracker.testing.owner' => $owner,
    ]);

    Http::fake(function (Request $request) use ($metadata) {
        if (str_contains($request->url(), 'rpc.test')) {
            $data = (string) $request->data()['params'][0]['data'];

            if (str_starts_with($data, trackerSelector('ownerOf(uint256)'))) {
                $current = strtolower((string) config('tracker.testing.owner'));

                return Http::response(['result' => '0x'.str_pad(substr($current, 2), 64, '0', STR_PAD_LEFT)]);
            }

            if (str_starts_with($data, trackerSelector('tokenURI(uint256)'))) {
                return Http::response(['result' => trackerAbiString('ipfs://bafyreleases/metadata.json')]);
            }

            return Http::response(['result' => '0x'], 200);
        }

        return Http::response($metadata === [] ? 'not json' : json_encode($metadata), 200);
    });
}

function releaseMetadata(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Cyberia Sessions vol. 1',
        'description' => 'Six tracks.',
        'image' => 'ipfs://bafycover/cover.jpg',
        'animation_url' => 'ipfs://bafypreview/one.mp3',
        'torrent' => [
            'info_hash' => 'C0FE'.str_repeat('a', 36),
            'name' => 'Cyberia Sessions vol. 1',
            'length' => 40_000_000,
            'category' => 'audio',
            'magnet' => 'magnet:?xt=urn:btih:c0fe'.str_repeat('a', 36).'&dn=sessions',
            'files' => [
                ['path' => 'one.flac', 'length' => 20_000_000],
                ['path' => 'two.flac', 'length' => 20_000_000],
            ],
        ],
    ], $overrides);
}

test('a token becomes a release, with every field read from the chain', function () {
    fakeToken(releaseMetadata());

    $response = $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12']);

    $response->assertCreated();

    $release = TrackerRelease::sole();

    expect($release->info_hash)->toBe('c0fe'.str_repeat('a', 36))
        ->and($release->name)->toBe('Cyberia Sessions vol. 1')
        ->and($release->category)->toBe('audio')
        ->and($release->media_kind)->toBe('audio')
        ->and($release->size_bytes)->toBe(40_000_000)
        ->and($release->file_count)->toBe(2)
        ->and($release->token_id)->toBe('12')
        // Read off `ownerOf`, never from the request — which is why the same
        // call from a stranger produces the same row.
        ->and($release->owner)->toBe('0x00000000000000000000000000000000000000aa')
        ->and($release->preview_url)->toBe('ipfs://bafypreview/one.mp3')
        ->and($release->cover_url)->toBe('ipfs://bafycover/cover.jpg');

    expect($response->json('release.token_url'))
        ->toContain('/token/0x546462fabf30734e63b64f32b30ec8add9b6eba7/instance/12');
});

test('nothing a caller sends can reach the release', function () {
    fakeToken(releaseMetadata());

    $this->postJson('/api/tracker/releases', [
        'chain_id' => 49406,
        'token_id' => '12',
        'name' => 'something else entirely',
        'owner' => '0x000000000000000000000000000000000000dead',
        'seeders' => 9000,
        'info_hash' => str_repeat('f', 40),
    ])->assertCreated();

    $release = TrackerRelease::sole();

    expect($release->name)->toBe('Cyberia Sessions vol. 1')
        ->and($release->owner)->toBe('0x00000000000000000000000000000000000000aa')
        ->and($release->seeders)->toBe(0)
        ->and($release->info_hash)->toBe('c0fe'.str_repeat('a', 36));
});

test('a magnet naming a different torrent is rebuilt, not believed', function () {
    fakeToken(releaseMetadata(['torrent' => ['magnet' => 'magnet:?xt=urn:btih:'.str_repeat('b', 40)]]));

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])->assertCreated();

    $release = TrackerRelease::sole();

    expect($release->magnet)->toContain('urn:btih:'.$release->info_hash)
        ->not->toContain(str_repeat('b', 40));

    // And it carries the tracker, so a client is not left waiting on the DHT.
    expect($release->magnet)->toContain(rawurlencode((string) config('tracker.announce_url')));
});

test('a token that describes no torrent is refused, and says which part is missing', function () {
    fakeToken(releaseMetadata(['torrent' => ['info_hash' => 'not a hash']]));

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'info hash'));

    expect(TrackerRelease::count())->toBe(0);
});

test('a token that points at something which is not metadata is refused', function () {
    fakeToken([]);

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ERC-721'));
});

test('a token the chain does not know is not a release', function () {
    config(['tracker.chains.49406.rpc_url' => 'https://rpc.test/']);

    // A revert answers with an `error` and no `result`, which is what an
    // unminted id looks like from outside.
    Http::fake(['rpc.test/*' => Http::response(['error' => ['message' => 'execution reverted']])]);

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '99'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'does not know'));
});

test('one torrent is one release, whoever minted it second', function () {
    fakeToken(releaseMetadata());

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])->assertCreated();
    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '13'])
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'already'));

    expect(TrackerRelease::count())->toBe(1);
});

test('registering the same token again picks up its new owner', function () {
    fakeToken(releaseMetadata());

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])->assertCreated();

    fakeToken(releaseMetadata(), owner: '0x00000000000000000000000000000000000000BB');

    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])->assertCreated();

    expect(TrackerRelease::count())->toBe(1)
        ->and(TrackerRelease::sole()->owner)->toBe('0x00000000000000000000000000000000000000bb');
});

test('the index answers with what was published, filtered and searchable', function () {
    fakeToken(releaseMetadata());
    $this->postJson('/api/tracker/releases', ['chain_id' => 49406, 'token_id' => '12'])->assertCreated();

    $this->getJson('/api/tracker/releases')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('releases.0.name', 'Cyberia Sessions vol. 1')
        ->assertJsonPath('context.announce_url', config('tracker.announce_url'));

    $this->getJson('/api/tracker/releases?category=video')->assertJsonPath('total', 0);
    $this->getJson('/api/tracker/releases?q=sessions')->assertJsonPath('total', 1);
    // An info hash in the search box is a lookup, not a substring match.
    $this->getJson('/api/tracker/releases?q=c0fe'.str_repeat('a', 36))->assertJsonPath('total', 1);
    $this->getJson('/api/tracker/releases?q='.str_repeat('e', 40))->assertJsonPath('total', 0);

    $this->getJson('/api/tracker/releases/c0fe'.str_repeat('a', 36))
        ->assertOk()
        ->assertJsonPath('release.file_count', 2);
});
