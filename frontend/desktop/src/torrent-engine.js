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
    MAX_TORRENTS,
    UPDATE_INTERVAL_MS,
    sanitizeSource,
    summarize,
} = require('./torrent-rules');

const dns = require('node:dns').promises;

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
