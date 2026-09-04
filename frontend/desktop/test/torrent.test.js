'use strict';

/**
 * The torrent client's rules, tested under plain Node like the rest of the
 * shell's logic — no display, no Electron binary, no swarm.
 *
 * Two of these carry weight beyond tidiness: what may become a torrent at all
 * (the last check before a socket opens, on the side of the boundary the page
 * cannot reach), and where files are written (a path from remote content would
 * be a write primitive).
 */

const assert = require('node:assert/strict');
const path = require('node:path');
const { test } = require('node:test');
const {
    MAX_READ_BYTES,
    MAX_TORRENTS,
    announceUrlFor,
    etaSeconds,
    resolveDownloadDir,
    sanitizeIndex,
    sanitizeSource,
    summarize,
} = require('../src/torrent-rules');

test('a bare info hash becomes a magnet, in either encoding', () => {
    assert.equal(
        sanitizeSource('  DD8255ECDC7CA55FB0BBF81323D87062DB1F6D1C '),
        'magnet:?xt=urn:btih:dd8255ecdc7ca55fb0bbf81323d87062db1f6d1c',
    );
    assert.equal(
        sanitizeSource('ANFHU5S6MDVJXP7UGBZO4XZQFJEDPXAJ'),
        'magnet:?xt=urn:btih:anfhu5s6mdvjxp7ugbzo4xzqfjedpxaj',
    );
});

test('magnets and https .torrent links are taken as they are', () => {
    const magnet =
        'magnet:?xt=urn:btih:dd8255ecdc7ca55fb0bbf81323d87062db1f6d1c&dn=debian';

    assert.equal(sanitizeSource(magnet), magnet);
    assert.equal(
        sanitizeSource('https://cdimage.debian.org/a.torrent'),
        'https://cdimage.debian.org/a.torrent',
    );
});

test('nothing else opens a socket', () => {
    assert.equal(sanitizeSource(''), null);
    assert.equal(sanitizeSource('   '), null);
    assert.equal(sanitizeSource(null), null);
    assert.equal(sanitizeSource(undefined), null);
    // A magnet naming no torrent.
    assert.equal(sanitizeSource('magnet:?dn=debian'), null);
    // Reading the local disk is not something a page may ask for.
    assert.equal(sanitizeSource('file:///etc/passwd'), null);
    assert.equal(sanitizeSource('http://example.org/a.torrent'), null);
    assert.equal(sanitizeSource(`https://example.org/${'a'.repeat(3000)}`), null);
});

test('downloads land under the system folder, never where a page says', () => {
    assert.equal(
        resolveDownloadDir({}, '/home/lain/Downloads'),
        path.join('/home/lain/Downloads', 'Cyberia'),
    );
    // An operator may move it; a relative path is not a move, it is a mistake.
    assert.equal(
        resolveDownloadDir({ CYBERIA_TORRENT_DIR: '/srv/torrents' }, '/home/lain/Downloads'),
        '/srv/torrents',
    );
    assert.equal(
        resolveDownloadDir({ CYBERIA_TORRENT_DIR: '../../etc' }, '/home/lain/Downloads'),
        path.join('/home/lain/Downloads', 'Cyberia'),
    );
});

const torrent = (fields) => ({
    infoHash: 'dd8255ecdc7ca55fb0bbf81323d87062db1f6d1c',
    name: 'debian.iso',
    length: 1024,
    downloaded: 512,
    uploaded: 0,
    progress: 0.5,
    downloadSpeed: 100,
    uploadSpeed: 0,
    numPeers: 3,
    paused: false,
    done: false,
    timeRemaining: 5000,
    files: [{ name: 'debian.iso', length: 1024, progress: 0.5 }],
    ...fields,
});

test('a torrent without metadata yet says so instead of showing 0%', () => {
    const summary = summarize(torrent({ name: '', length: 0, progress: 0 }));

    assert.equal(summary.status, 'metadata');
    assert.equal(summary.length, 0);
});

test('status distinguishes paused, seeding and stopped', () => {
    assert.equal(summarize(torrent({ paused: true })).status, 'paused');
    assert.equal(
        summarize(torrent({ done: true, progress: 1 })).status,
        'seeding',
    );
    assert.equal(summarize(torrent()).status, 'downloading');

    const failed = summarize(torrent(), 'no route to host');
    assert.equal(failed.status, 'error');
    assert.equal(failed.error, 'no route to host');
});

test('a summary carries numbers and names, never disk paths or peers', () => {
    const summary = summarize(
        torrent({ path: '/home/lain/Downloads/Cyberia', _peers: ['1.2.3.4'] }),
    );

    assert.deepEqual(Object.keys(summary).sort(), [
        'downloadSpeed',
        'downloaded',
        'error',
        'eta',
        'files',
        'infoHash',
        'length',
        'magnet',
        'name',
        'peers',
        'progress',
        'status',
        'uploadSpeed',
        'uploaded',
    ]);

    // Where this machine keeps the file is the shell's business. What crosses
    // is the path *inside the torrent*, which is part of the torrent's own
    // metadata and is what a release's file list is made of.
    assert.equal(JSON.stringify(summary).includes('/home/lain'), false);
    assert.deepEqual(summary.files, [
        { name: 'debian.iso', path: 'debian.iso', length: 1024, progress: 0.5 },
    ]);
});

test('a file inside a folder keeps its path, with separators normalised', () => {
    const summary = summarize(
        torrent({
            files: [
                { name: '01.flac', path: 'Album\\01.flac', length: 8, progress: 1 },
            ],
        }),
    );

    assert.equal(summary.files[0].path, 'Album/01.flac');
});

test('the tracker a new torrent announces to comes from the shell, not a page', () => {
    // Derived from the site this shell was pointed at. A page naming a tracker
    // would be a page deciding who this client talks to.
    assert.equal(
        announceUrlFor('https://cyberia.church/wallet?x=1'),
        'https://cyberia.church/announce',
    );
    assert.equal(announceUrlFor('http://localhost:8000'), 'http://localhost:8000/announce');
    assert.equal(announceUrlFor('not a url'), '');
});

test('a file index from the page is an index into that torrent or nothing', () => {
    assert.equal(sanitizeIndex(0, 3), 0);
    assert.equal(sanitizeIndex('2', 3), 2);
    assert.equal(sanitizeIndex(3, 3), null);
    assert.equal(sanitizeIndex(-1, 3), null);
    assert.equal(sanitizeIndex(1.5, 3), null);
    assert.equal(sanitizeIndex('../../etc/passwd', 3), null);
});

test('an ETA exists only while there is one to have', () => {
    assert.equal(etaSeconds(torrent({ timeRemaining: 90_000 })), 90);
    assert.equal(etaSeconds(torrent({ timeRemaining: Infinity })), null);
    assert.equal(etaSeconds(torrent({ timeRemaining: 0 })), null);
    assert.equal(etaSeconds(torrent({ done: true, timeRemaining: 5000 })), null);
});

test('the caps the page is told about are the caps enforced here', () => {
    assert.equal(MAX_READ_BYTES, 10 * 1024 * 1024);
    assert.ok(MAX_TORRENTS > 0 && MAX_TORRENTS <= 32);
});
