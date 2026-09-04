import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import {
    base32ToHex,
    decodeBencode,
    parseMagnet,
    parseTorrent,
    sliceValue,
} from '@/lib/wallet/bencode';
import { formatBytes } from '@/lib/wallet/ipfs';
import {
    advance,
    formatSupport,
    formatTime,
    mimeFor,
    trackTitle,
    tracksFromRelease,
    tracksFromTorrent,
} from '@/lib/wallet/player';
import {
    buildReleaseMetadata,
    isPlayable,
    magnetFor,
    mediaKindOf,
} from '@/lib/wallet/tracker';

/**
 * The parts of the tracker that decide what goes on chain, and the parts that
 * decide which swarm a page is talking about.
 *
 * Two of these are unforgiving. An info hash is SHA-1 over the *original*
 * bytes of a torrent's info dictionary — one byte out and it names a swarm
 * nobody is in — and the metadata document is what a CID is made of, so it is
 * the release forever. The rest is the player deciding what it can honestly
 * play, which is mostly a question about containers no browser ever decoded.
 */

const utf8 = new TextEncoder();

const concat = (parts) => {
    const total = parts.reduce((sum, part) => sum + part.length, 0);
    const out = new Uint8Array(total);
    let at = 0;

    for (const part of parts) {
        out.set(part, at);
        at += part.length;
    }

    return out;
};

/** A bencoded byte string, written the way a client writes one. */
const bstr = (value) => {
    const bytes = typeof value === 'string' ? utf8.encode(value) : value;

    return concat([utf8.encode(`${bytes.length}:`), bytes]);
};

const raw = (text) => utf8.encode(text);

test('a single-file torrent gives up its hash, name and size', async () => {
    const pieces = new Uint8Array(20).fill(7);
    const info = concat([
        raw('d'),
        bstr('length'),
        raw('i1024e'),
        bstr('name'),
        bstr('debian.iso'),
        bstr('piece length'),
        raw('i16384e'),
        bstr('pieces'),
        bstr(pieces),
        raw('e'),
    ]);
    const file = concat([
        raw('d'),
        bstr('announce'),
        bstr('https://cyberia.church/announce'),
        bstr('info'),
        info,
        raw('e'),
    ]);

    const parsed = await parseTorrent(file);

    // Computed here from the bytes this test wrote, by a different SHA-1 than
    // the browser's — so this checks the slicing, which is the part that goes
    // wrong: hashing a re-encoding of the dictionary gives a different swarm.
    const expected = createHash('sha1').update(Buffer.from(info)).digest('hex');

    assert.equal(parsed.infoHash, expected);
    assert.equal(parsed.name, 'debian.iso');
    assert.equal(parsed.length, 1024);
    assert.deepEqual(parsed.files, [{ path: 'debian.iso', length: 1024 }]);
    assert.deepEqual(parsed.trackers, ['https://cyberia.church/announce']);
    assert.equal(parsed.v2Only, false);
});

test('a multi-file torrent keeps the paths and adds the sizes up', async () => {
    const info = concat([
        raw('d'),
        bstr('files'),
        raw('l'),
        raw('d'),
        bstr('length'),
        raw('i10e'),
        bstr('path'),
        concat([raw('l'), bstr('CD1'), bstr('01.flac'), raw('e')]),
        raw('e'),
        raw('d'),
        bstr('length'),
        raw('i32e'),
        bstr('path'),
        concat([raw('l'), bstr('02.flac'), raw('e')]),
        raw('e'),
        raw('e'),
        bstr('name'),
        bstr('Album'),
        bstr('piece length'),
        raw('i16384e'),
        bstr('pieces'),
        bstr(new Uint8Array(20)),
        raw('e'),
    ]);
    const file = concat([
        raw('d'),
        bstr('announce-list'),
        concat([
            raw('l'),
            concat([raw('l'), bstr('https://one/announce'), raw('e')]),
            concat([raw('l'), bstr('udp://two:80'), raw('e')]),
            raw('e'),
        ]),
        bstr('info'),
        info,
        raw('e'),
    ]);

    const parsed = await parseTorrent(file);

    assert.equal(parsed.name, 'Album');
    assert.equal(parsed.length, 42);
    assert.deepEqual(parsed.files, [
        { path: 'CD1/01.flac', length: 10 },
        { path: '02.flac', length: 32 },
    ]);
    assert.deepEqual(parsed.trackers, ['https://one/announce', 'udp://two:80']);
    assert.deepEqual(sliceValue(file, 'info'), info);
});

test('a torrent that is only v2 says so instead of naming a swarm it cannot', async () => {
    const info = concat([
        raw('d'),
        bstr('meta version'),
        raw('i2e'),
        bstr('name'),
        bstr('v2 only'),
        raw('e'),
    ]);

    const parsed = await parseTorrent(
        concat([raw('d'), bstr('info'), info, raw('e')]),
    );

    assert.equal(parsed.v2Only, true);
});

test('bencode refuses what a client would never have written', () => {
    assert.throws(() => decodeBencode(raw('i03e')));
    assert.throws(() => decodeBencode(raw('4:ab')));
    assert.throws(() => decodeBencode(raw('d1:ae')));
    assert.deepEqual(decodeBencode(raw('i-7e')), -7);
});

test('a magnet is read in both spellings of a hash', () => {
    const hex = parseMagnet(
        'magnet:?xt=urn:btih:DD8255ECDC7CA55FB0BBF81323D87062DB1F6D1C&dn=debian&tr=https%3A%2F%2Fcyberia.church%2Fannounce',
    );

    assert.equal(hex.infoHash, 'dd8255ecdc7ca55fb0bbf81323d87062db1f6d1c');
    assert.equal(hex.name, 'debian');
    assert.deepEqual(hex.trackers, ['https://cyberia.church/announce']);

    // Base32 is 32 characters of the same twenty bytes, and clients write both.
    assert.equal(base32ToHex('a'.repeat(32)), '0'.repeat(40));
    assert.equal(
        parseMagnet('magnet:?xt=urn:btih:ANFHU5S6MDVJXP7UGBZO4XZQFJEDPXAJ')
            .infoHash,
        base32ToHex('anfhu5s6mdvjxp7ugbzo4xzqfjedpxaj'),
    );

    assert.equal(parseMagnet('magnet:?dn=nothing'), null);
    assert.equal(parseMagnet('https://example.com/x.torrent'), null);
    assert.equal(base32ToHex('not base32 at all'), null);
});

test('what a release is, is decided by the names of the files in it', () => {
    assert.equal(mediaKindOf([{ path: 'a/b.mkv', length: 1 }]), 'video');
    assert.equal(mediaKindOf([{ path: '01.flac', length: 1 }]), 'audio');
    assert.equal(
        mediaKindOf([
            { path: 'film.mkv', length: 1 },
            { path: 'ost/01.flac', length: 1 },
        ]),
        'mixed',
    );
    assert.equal(mediaKindOf([{ path: 'notes.txt', length: 1 }]), 'other');
    assert.equal(mediaKindOf([]), 'other');

    assert.equal(isPlayable('a.MP3'), true);
    assert.equal(isPlayable('a.nfo'), false);
});

test('the metadata a release is minted as says the same thing twice, on purpose', () => {
    const metadata = buildReleaseMetadata({
        name: '  Cyberia Sessions  ',
        description: ' six tracks ',
        infoHash: 'C0FE'.padEnd(40, 'a'),
        files: [
            { path: '01.flac', length: 20 },
            { path: '02.flac', length: 22 },
        ],
        category: 'audio',
        cover: 'ipfs://bafycover',
        preview: 'ipfs://bafypreview',
        announceUrl: 'https://cyberia.church/announce',
        siteUrl: 'https://cyberia.church/tracker/x',
    });

    // The standard half — what a marketplace that never heard of this tracker
    // renders — and the `torrent` half, which is what the index reads.
    assert.equal(metadata.name, 'Cyberia Sessions');
    assert.equal(metadata.description, 'six tracks');
    assert.equal(metadata.image, 'ipfs://bafycover');
    assert.equal(metadata.animation_url, 'ipfs://bafypreview');
    assert.deepEqual(metadata.attributes, [
        {
            trait_type: 'Info hash',
            value: 'c0feaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        },
        { trait_type: 'Files', value: '2' },
        { trait_type: 'Size', value: '42' },
        { trait_type: 'Category', value: 'audio' },
    ]);

    assert.equal(
        metadata.torrent.info_hash,
        'c0feaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    );
    assert.equal(metadata.torrent.length, 42);
    assert.equal(metadata.torrent.media, 'audio');
    assert.deepEqual(metadata.torrent.files, [
        { path: '01.flac', length: 20 },
        { path: '02.flac', length: 22 },
    ]);
    assert.equal(
        metadata.torrent.magnet,
        magnetFor(
            'c0feaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'Cyberia Sessions',
            'https://cyberia.church/announce',
        ),
    );
});

test('a release published from a magnet has no file list, and does not invent one', () => {
    const metadata = buildReleaseMetadata({
        name: 'from a magnet',
        infoHash: 'b'.repeat(40),
        announceUrl: 'https://cyberia.church/announce',
    });

    assert.deepEqual(metadata.torrent.files, []);
    assert.equal(metadata.torrent.length, 0);
    assert.equal('image' in metadata, false);
    assert.equal('animation_url' in metadata, false);
});

test('sizes and clocks read the way a person reads them', () => {
    assert.equal(formatBytes(512), '512 B');
    assert.equal(formatBytes(1024), '1 KB');
    assert.equal(formatBytes(1024 ** 3 * 4), '4.0 GB');
    // A release can be bigger than the pinning screen ever sees, which is why
    // the shared formatter grew a terabyte.
    assert.equal(formatBytes(1024 ** 4 * 2), '2.0 TB');

    assert.equal(formatTime(0), '0:00');
    assert.equal(formatTime(61), '1:01');
    assert.equal(formatTime(3661), '1:01:01');
    // Unknown is not zero: one means "wait", the other means "nothing here".
    assert.equal(formatTime(null), '--:--');
    assert.equal(formatTime(Number.NaN), '--:--');
});

test('the player knows what this browser will refuse to decode', () => {
    assert.equal(formatSupport('film.mp4'), 'browser');
    assert.equal(formatSupport('song.flac'), 'browser');
    // Matroska and AVI never had a decoder in a browser, and between them they
    // are most of what a film in a swarm actually is.
    assert.equal(formatSupport('film.mkv'), 'external');
    assert.equal(formatSupport('old.avi'), 'external');
    assert.equal(formatSupport('mystery.bin'), 'unknown');

    assert.equal(mimeFor('a.mkv'), 'video/x-matroska');
    assert.equal(mimeFor('a.flac'), 'audio/flac');
    assert.equal(trackTitle('CD1/01 - the wired.flac'), '01 - the wired');
});

test('a playlist ends rather than quietly starting again', () => {
    assert.equal(advance(0, 3, 'off'), 1);
    assert.equal(advance(2, 3, 'off'), null);
    assert.equal(advance(2, 3, 'all'), 0);
    assert.equal(advance(1, 3, 'one'), 1);
    assert.equal(advance(0, 0, 'all'), null);
});

test('a release plays its preview anywhere, and a swarm plays its own files', () => {
    const release = {
        info_hash: 'c'.repeat(40),
        name: 'Album',
        preview_url: 'ipfs://bafypreview/01.mp3',
        files: [{ path: '01.flac', length: 10 }],
    };

    // Anywhere at all: this is a file on IPFS, so it needs no client, no
    // download and no peers.
    const anywhere = tracksFromRelease(release);

    assert.equal(anywhere.length, 1);
    assert.equal(anywhere[0].kind, 'audio');
    assert.equal(anywhere[0].support, 'browser');

    // A release with nothing pinned plays nothing, rather than listing rows
    // that would do nothing when pressed.
    assert.deepEqual(tracksFromRelease({ ...release, preview_url: null }), []);

    // The swarm's own files come from the client that holds them, so the index
    // a stream is asked for is an index into the torrent that actually exists.
    const swarm = tracksFromTorrent(
        {
            infoHash: 'c'.repeat(40),
            name: 'Album',
            files: [
                { path: '01.flac', name: '01.flac', length: 10 },
                { path: 'cover.jpg', name: 'cover.jpg', length: 2 },
                { path: 'film.mkv', name: 'film.mkv', length: 30 },
            ],
        },
        async () => 'cyberia-media://x',
    );

    assert.deepEqual(
        swarm.map((entry) => entry.title),
        ['01', 'film'],
    );
    // A cover is not a track, and the video says up front that this browser
    // will not decode it. Its index is still the torrent's, not the playlist's.
    assert.equal(swarm[1].id, `${'c'.repeat(40)}:2`);
    assert.equal(swarm[1].support, 'external');
    assert.equal(swarm[1].kind, 'video');
    assert.equal(typeof swarm[0].resolve, 'function');
});
