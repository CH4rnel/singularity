'use strict';

/**
 * The rules the torrent client runs by, kept away from Electron so they can be
 * tested under plain Node — the same reason `config.js` is shaped this way.
 *
 * Everything here is a limit or a translation. The limits exist because the
 * page asking for a download is remote content: it is our own site, but a
 * compromised page must not be able to fill a disk, read one, or open an
 * unbounded number of sockets in someone's name.
 */

const path = require('node:path');

/**
 * Bumped when the bridge's shape changes, so the wallet can tell an old shell
 * from a missing feature instead of calling a function that is not there.
 *
 * 2 — seeding, streaming and per-file paths. The page can now create a torrent
 * from files the person picks in a native dialog, play one out of a live swarm
 * through the shell's own scheme, and hand a file to the system player.
 */
const ENGINE_VERSION = 2;

/** Torrents allowed at once. A swarm each, and each one costs sockets. */
const MAX_TORRENTS = 8;

/**
 * Largest file the shell will hand back to the page.
 *
 * This is the IPFS handoff and nothing else: a file that comes back crosses
 * IPC as base64 and is then posted to a pinning endpoint that has its own cap.
 * A film is not pinnable and is not offered.
 */
const MAX_READ_BYTES = 10 * 1024 * 1024;

/** How often progress is pushed to the window. */
const UPDATE_INTERVAL_MS = 1000;

/**
 * Files one `seed` may take.
 *
 * Creating a torrent hashes every byte of every file, in this process. The cap
 * is not about disk or memory but about the dialog: somebody who selects a
 * whole music library should be told that is more than this will do, rather
 * than watching a window that appears to have frozen.
 */
const MAX_SEED_FILES = 500;

/**
 * Where the streaming server lives inside its own http server, and the scheme
 * the page is handed instead of that server's address.
 *
 * The page is on https and cannot load `http://127.0.0.1` — that is mixed
 * content, blocked with no visible error. So the shell registers a scheme of
 * its own, marked secure, and proxies it to the local server; the page never
 * learns a port and never talks to one.
 */
const STREAM_PATHNAME = '/stream';
const STREAM_SCHEME = 'cyberia-media';

const HEX_HASH = /^[0-9a-f]{40}$/i;
const BASE32_HASH = /^[a-z2-7]{32}$/i;

/**
 * What the page asked for, as something the engine may take — or null.
 *
 * The wallet checks this too, and that check is the useful one for the person
 * typing. This one is the check that matters: it is the last place before a
 * socket opens, and it is on the side of the boundary the page cannot reach.
 * `file://` and plain `http://` are refused here — one would ask the shell to
 * read the local disk, the other is not a link this offers.
 */
function sanitizeSource(value) {
    const source = String(value ?? '').trim();

    if (source === '') {
        return null;
    }

    if (HEX_HASH.test(source) || BASE32_HASH.test(source)) {
        return `magnet:?xt=urn:btih:${source.toLowerCase()}`;
    }

    if (source.toLowerCase().startsWith('magnet:?')) {
        return /xt=urn:btih:[0-9a-z]{32,40}/i.test(source) ? source : null;
    }

    if (/^https:\/\/[^\s]+$/i.test(source) && source.length <= 2048) {
        return source;
    }

    return null;
}

/**
 * Where downloads land.
 *
 * Chosen by the shell, never by the page: a path from remote content is a
 * write primitive. `CYBERIA_TORRENT_DIR` is an operator's override, not
 * something the site can set.
 */
function resolveDownloadDir(env, downloads) {
    const override = String(env?.CYBERIA_TORRENT_DIR ?? '').trim();

    if (override !== '' && path.isAbsolute(override)) {
        return override;
    }

    return path.join(downloads, 'Cyberia');
}

/**
 * The tracker every torrent created here announces to.
 *
 * Derived from the site the shell is pointed at rather than taken from the
 * page: a URL from remote content is a URL this client would then announce to,
 * and the whole reason the shell owns the engine is that the page does not get
 * to choose who it talks to.
 */
function announceUrlFor(appUrl) {
    try {
        return `${new URL(String(appUrl)).origin}/announce`;
    } catch {
        return '';
    }
}

/** A file index from the page, or null. Never an index into something else. */
function sanitizeIndex(value, count) {
    const index = Number(value);

    return Number.isInteger(index) && index >= 0 && index < Number(count)
        ? index
        : null;
}

/** Seconds left at the current rate, or null when there is no honest answer. */
function etaSeconds(torrent) {
    const remaining = Number(torrent.timeRemaining);

    if (!Number.isFinite(remaining) || remaining <= 0 || torrent.done) {
        return null;
    }

    return Math.round(remaining / 1000);
}

/**
 * One torrent as the wallet's screen understands it.
 *
 * Deliberately a plain object rather than anything from the client: this
 * crosses two process boundaries and ends up in a web page, so it carries the
 * numbers on screen and nothing else — no file paths, no peer addresses, no
 * handles.
 */
function summarize(torrent, error = null) {
    const ready = Boolean(torrent.name) && Number(torrent.length) > 0;

    let status = 'downloading';

    if (error) {
        status = 'error';
    } else if (torrent.paused) {
        status = 'paused';
    } else if (!ready) {
        status = 'metadata';
    } else if (torrent.done) {
        status = 'seeding';
    }

    return {
        infoHash: String(torrent.infoHash ?? ''),
        name: String(torrent.name ?? ''),
        // The link that lets somebody else join this swarm — the whole of what
        // publishing a release hands to the page.
        magnet: String(torrent.magnetURI ?? ''),
        status,
        progress: Number(torrent.progress ?? 0),
        length: Number(torrent.length ?? 0),
        downloaded: Number(torrent.downloaded ?? 0),
        uploaded: Number(torrent.uploaded ?? 0),
        downloadSpeed: Number(torrent.downloadSpeed ?? 0),
        uploadSpeed: Number(torrent.uploadSpeed ?? 0),
        peers: Number(torrent.numPeers ?? 0),
        eta: etaSeconds(torrent),
        files: (torrent.files ?? []).map((file) => ({
            name: String(file.name ?? ''),
            // The path *inside the torrent*, not on this disk: it is part of
            // the torrent's own metadata, it is what a release's file list is
            // made of, and it is how the stream server addresses a file.
            path: String(file.path ?? file.name ?? '').replace(/\\/g, '/'),
            length: Number(file.length ?? 0),
            progress: Number(file.progress ?? 0),
        })),
        error: error ? String(error) : null,
    };
}

module.exports = {
    ENGINE_VERSION,
    MAX_READ_BYTES,
    MAX_SEED_FILES,
    MAX_TORRENTS,
    STREAM_PATHNAME,
    STREAM_SCHEME,
    UPDATE_INTERVAL_MS,
    announceUrlFor,
    etaSeconds,
    resolveDownloadDir,
    sanitizeIndex,
    sanitizeSource,
    summarize,
};
