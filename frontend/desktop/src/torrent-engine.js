'use strict';

/**
 * The BitTorrent client itself, in a process of its own.
 *
 * This is the part a browser tab cannot be: real sockets, so the mainline DHT
 * (UDP), peer exchange and ordinary TCP/uTP peers all work and a magnet finds
 * the same swarm any other client would.
 *
 * It runs as an Electron `utilityProcess` rather than inside the main process
 * for two reasons: piece verification is CPU work that would otherwise compete
 * with the window, and a client that crashes on a malformed torrent should not
 * take the wallet down with it.
 *
 * WebTorrent is ESM with top-level await, so it can only be loaded through a
 * dynamic `import()` — and only from outside the asar archive, since Node's
 * ESM loader does not read archives. `electron-builder.yml` unpacks this file
 * and `node_modules` for exactly that reason.
 */

const {
    MAX_READ_BYTES,
    MAX_SEED_FILES,
    MAX_TORRENTS,
    STREAM_PATHNAME,
    UPDATE_INTERVAL_MS,
    sanitizeIndex,
    sanitizeSource,
    summarize,
} = require('./torrent-rules');

const dns = require('node:dns').promises;
const fs = require('node:fs');
const nodePath = require('node:path');

const port = process.parentPort;

/**
 * The nodes a fresh client asks first, and the ports they answer on.
 *
 * Upstream ships the first three. Two of them have been unreliable for years
 * and the third, `dht.transmissionbt.com`, is IPv6-first — so on a machine
 * with no IPv6 route the routing table can end up empty, and a client with no
 * peers looks broken rather than blocked. `dht.libtorrent.org` is the fourth
 * node most clients also carry.
 */
const BOOTSTRAP = [
    ['router.bittorrent.com', 6881],
    ['router.utorrent.com', 6881],
    ['dht.transmissionbt.com', 6881],
    ['dht.libtorrent.org', 25401],
];

/**
 * Bootstrap addresses, resolved to IPv4 here rather than by the DHT.
 *
 * This is the difference between a working client and a client that finds
 * nobody: given a hostname whose AAAA record comes first, the DHT's UDP4
 * socket has nowhere to send, and every lookup after that starts from an empty
 * routing table. A name that cannot be resolved is still passed through — the
 * client may have a resolver we do not.
 */
async function bootstrapNodes() {
    const resolved = await Promise.all(
        BOOTSTRAP.map(async ([host, dhtPort]) => {
            try {
                const addresses = await dns.resolve4(host);

                return addresses.slice(0, 2).map((address) => `${address}:${dhtPort}`);
            } catch {
                return [`${host}:${dhtPort}`];
            }
        }),
    );

    return resolved.flat();
}

/**
 * Where files are written, handed over as a launch argument by the main
 * process. A page never names a path, and there is no message that changes it.
 */
const downloadDir = process.argv[2] ?? '';

let client = null;

/**
 * The streaming server, started the first time something is played.
 *
 * It is an ordinary http server on a loopback port, and the window cannot
 * reach it: the page is on https, and `http://127.0.0.1` from an https page is
 * mixed content. The main process proxies its own scheme to this, which is
 * also what keeps the port out of the page — see `torrent.js`.
 *
 * It exists because playing from a swarm is not the same as playing a file: a
 * download in progress is a sparse file full of holes, and reading it off disk
 * gives silence and garbage. The server waits for the pieces the player is
 * actually asking for, which is what makes seeking work before the download
 * has finished.
 */
let server = null;
let serverPort = 0;

/** Why a torrent stopped, by info hash. Cleared when it is removed. */
const failures = new Map();

async function ensureClient() {
    if (client) {
        return client;
    }

    const { default: WebTorrent } = await import('webtorrent');

    // DHT, trackers and local discovery are all on: that is what makes this a
    // real client, and nothing here is turned off for the sake of looking
    // quieter than it is.
    client = new WebTorrent({ dht: { bootstrap: await bootstrapNodes() } });

    client.on('error', (error) => {
        port.postMessage({
            event: 'engine-error',
            value: String(error?.message ?? error),
        });
    });

    return client;
}

async function ensureServer() {
    if (serverPort !== 0) {
        return serverPort;
    }

    const engine = await ensureClient();

    server = engine.createServer(
        // `hostname` makes the server refuse any request whose Host header is
        // not the loopback address it listens on, which is what stops a page
        // in any browser on this machine from reaching it by DNS rebinding.
        { pathname: STREAM_PATHNAME, hostname: '127.0.0.1' },
        'node',
    );

    await new Promise((resolve, reject) => {
        server.server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });

    serverPort = server.server.address().port;

    return serverPort;
}

/**
 * Where a selection of files should be seeded from.
 *
 * The torrent's name comes from what was picked — one folder is a folder,
 * several files are several files — and the store has to be the directory that
 * *contains* it, or the client would look for the content one level too deep
 * and immediately start re-downloading what is already on the disk.
 */
function seedRoot(paths) {
    const first = paths[0];

    if (paths.length === 1 && fs.statSync(first).isDirectory()) {
        return nodePath.dirname(first);
    }

    return nodePath.dirname(first);
}

function list() {
    return (client?.torrents ?? []).map((torrent) =>
        summarize(torrent, failures.get(torrent.infoHash) ?? null),
    );
}

async function torrentFor(infoHash) {
    const torrent = client ? await client.get(String(infoHash)) : null;

    if (!torrent) {
        throw new Error('That torrent is not in the client');
    }

    return torrent;
}

/**
 * Wait for a torrent to know its own hash.
 *
 * `client.add()` hands back the torrent before it has parsed the id, so a
 * summary taken immediately carries an empty info hash — and the screen would
 * have nothing to pause or remove by. This is a short poll rather than an
 * event because the client does not promise one; the list arrives a moment
 * later either way.
 */
async function waitForInfoHash(torrent, timeoutMs = 2000) {
    const deadline = Date.now() + timeoutMs;

    while (!torrent.infoHash && Date.now() < deadline) {
        await new Promise((resolve) => setTimeout(resolve, 25));
    }

    return torrent.infoHash ?? '';
}

const actions = {
    async add(source) {
        const normalized = sanitizeSource(source);

        if (normalized === null) {
            throw new Error('That is not a magnet link, an info hash or a .torrent link');
        }

        const engine = await ensureClient();
        const existing = await engine.get(normalized);

        if (existing) {
            return summarize(existing, failures.get(existing.infoHash) ?? null);
        }

        if (engine.torrents.length >= MAX_TORRENTS) {
            throw new Error(`This client holds ${MAX_TORRENTS} torrents at a time`);
        }

        // Resolved as soon as the torrent has an identity, not when its
        // metadata arrives: a magnet with no peers yet would otherwise hang the
        // button forever instead of appearing in the list as what it is.
        const torrent = engine.add(normalized, { path: downloadDir });

        torrent.on('error', (error) => {
            failures.set(torrent.infoHash, String(error?.message ?? error));
        });
        torrent.on('metadata', () => failures.delete(torrent.infoHash));

        await waitForInfoHash(torrent);

        return summarize(torrent, null);
    },

    async list() {
        return list();
    },

    async pause(infoHash) {
        (await torrentFor(infoHash)).pause();

        return true;
    },

    async resume(infoHash) {
        (await torrentFor(infoHash)).resume();

        return true;
    },

    async remove(infoHash, deleteFiles) {
        const torrent = await torrentFor(infoHash);

        failures.delete(torrent.infoHash);
        await client.remove(torrent.infoHash, {
            destroyStore: Boolean(deleteFiles),
        });

        return true;
    },

    /**
     * One finished file, for pinning it to IPFS.
     *
     * Capped, and only ever a file inside a torrent this client is holding —
     * the page names an index, never a path, so there is no way to ask this
     * for a file it did not download.
     */
    /**
     * Create a torrent out of files on this disk and start seeding it.
     *
     * This is the half of the tracker a browser cannot be. Every byte is
     * hashed here, the tracker the shell was pointed at is written into the
     * torrent, and what comes back is a summary carrying the info hash, the
     * magnet and the file list — which is exactly what the wallet needs to
     * mint the release. Nothing is uploaded anywhere: the files stay where
     * they are, and what is published is a link to them.
     */
    async seed(paths, announceUrl) {
        const chosen = (Array.isArray(paths) ? paths : [])
            .map((entry) => String(entry))
            .filter((entry) => entry !== '' && nodePath.isAbsolute(entry));

        if (chosen.length === 0) {
            throw new Error('Nothing was selected');
        }

        if (chosen.length > MAX_SEED_FILES) {
            throw new Error(`This creates torrents from up to ${MAX_SEED_FILES} files at a time`);
        }

        const engine = await ensureClient();

        if (engine.torrents.length >= MAX_TORRENTS) {
            throw new Error(`This client holds ${MAX_TORRENTS} torrents at a time`);
        }

        const announce = String(announceUrl ?? '');

        const torrent = await new Promise((resolve, reject) => {
            const created = engine.seed(
                chosen,
                {
                    path: seedRoot(chosen),
                    // The tracker goes in as the torrent's own announce list,
                    // so anybody who takes this magnet finds the swarm without
                    // waiting on the DHT — and so the release, once minted,
                    // reports to the index it is listed on.
                    announceList: announce === '' ? [] : [[announce]],
                },
                () => resolve(created),
            );

            created.on('error', (error) => reject(new Error(String(error?.message ?? error))));
        });

        return summarize(torrent, null);
    },

    /**
     * A URL the window can put in a media element.
     *
     * Selecting the file is the point: it tells the client which pieces the
     * person is actually waiting for, so a film in the middle of a download
     * starts playing from the beginning instead of whenever the rarest piece
     * happens to arrive.
     */
    async streamUrl(infoHash, index) {
        const torrent = await torrentFor(infoHash);
        const position = sanitizeIndex(index, torrent.files.length);

        if (position === null) {
            throw new Error('That torrent has no such file');
        }

        const file = torrent.files[position];

        file.select();

        const port = await ensureServer();
        const inside = String(file.path ?? file.name).replace(/\\/g, '/');

        return {
            url: `http://127.0.0.1:${port}${STREAM_PATHNAME}/${torrent.infoHash}/${encodeURIComponent(inside)}`,
            name: String(file.name ?? ''),
            length: Number(file.length ?? 0),
        };
    },

    /**
     * Where one file actually is, for handing to the system's own player.
     *
     * The answer never reaches the page — the main process opens the path and
     * tells the window nothing — because a disk path in remote content is the
     * beginning of a read primitive.
     */
    async filePath(infoHash, index) {
        const torrent = await torrentFor(infoHash);
        const position = sanitizeIndex(index, torrent.files.length);

        if (position === null) {
            throw new Error('That torrent has no such file');
        }

        const file = torrent.files[position];

        return nodePath.join(String(torrent.path ?? ''), String(file.path ?? file.name));
    },

    async read(infoHash, index) {
        const torrent = await torrentFor(infoHash);
        const file = torrent.files[Number(index)];

        if (!file) {
            throw new Error('That torrent has no such file');
        }

        if (file.length > MAX_READ_BYTES) {
            throw new Error('That file is too large to pin from here');
        }

        if (file.progress < 1) {
            throw new Error('That file has not finished downloading');
        }

        const buffer = await file.arrayBuffer();

        return {
            name: String(file.name ?? 'file'),
            bytes: file.length,
            base64: Buffer.from(buffer).toString('base64'),
        };
    },
};

port.on('message', async (message) => {
    const { id, action, args = [] } = message.data ?? {};

    if (!Object.hasOwn(actions, action)) {
        port.postMessage({ id, ok: false, error: `Unknown action ${action}` });

        return;
    }

    try {
        port.postMessage({ id, ok: true, value: await actions[action](...args) });
    } catch (error) {
        port.postMessage({
            id,
            ok: false,
            error: String(error?.message ?? error),
        });
    }
});

// Progress is pushed rather than polled: the window would otherwise ask twice a
// second forever, and most of the time nothing has changed.
setInterval(() => {
    if (client && client.torrents.length > 0) {
        port.postMessage({ event: 'torrents', value: list() });
    }
}, UPDATE_INTERVAL_MS).unref();
