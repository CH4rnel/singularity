'use strict';

/**
 * The shell's side of the torrent client.
 *
 * The wallet screen that drives this is remote content — our own site, but a
 * page all the same — so everything it may do is decided here: it may add a
 * link, list what is downloading, pause, remove, and read back one finished
 * file under a cap. It cannot choose where files are written, cannot name a
 * path, and cannot start the client at all until the person in front of the
 * machine has agreed once, in a dialog the page cannot draw.
 *
 * That dialog is the point where the honest sentence gets said: peers see your
 * IP address, and the app's proxy setting does not cover this traffic — that
 * setting is for web requests, and these are raw sockets.
 */

const fs = require('node:fs');
const path = require('node:path');
const { app, dialog, ipcMain, net, shell, utilityProcess } = require('electron');
const {
    ENGINE_VERSION,
    MAX_READ_BYTES,
    MAX_SEED_FILES,
    MAX_TORRENTS,
    STREAM_SCHEME,
    announceUrlFor,
    resolveDownloadDir,
} = require('./torrent-rules');

/** Answered once and remembered, like any other decision about this machine. */
const CONSENT_FILE = 'torrent.json';

let engine = null;
let starting = null;
let consented = null;
let nextId = 1;

/** Calls waiting on the engine, by id. */
const pending = new Map();

/** Set by `register`, so this module never reaches for a window of its own. */
let context = {
    getWindow: () => null,
    getContents: () => null,
    isTrusted: () => false,
    appUrl: '',
};

function consentPath() {
    return path.join(app.getPath('userData'), CONSENT_FILE);
}

function readConsent() {
    if (consented !== null) {
        return consented;
    }

    try {
        const stored = JSON.parse(fs.readFileSync(consentPath(), 'utf8'));
        consented = stored?.accepted === true;
    } catch {
        // No file, unreadable file, or nonsense in it: all mean "not yet".
        consented = false;
    }

    return consented;
}

function writeConsent(accepted) {
    consented = accepted;

    try {
        fs.writeFileSync(
            consentPath(),
            JSON.stringify({ accepted, at: new Date().toISOString() }),
        );
    } catch (error) {
        console.warn('[cyberia] could not store the torrent consent:', error.message);
    }
}

function downloadDir() {
    return resolveDownloadDir(process.env, app.getPath('downloads'));
}

/**
 * The engine's entry point on the real filesystem.
 *
 * WebTorrent is ESM with top-level await, and Node's ESM loader cannot read an
 * asar archive — so both this file and `node_modules` are unpacked at build
 * time and the packaged path is rewritten to match.
 */
function enginePath() {
    return path
        .join(__dirname, 'torrent-engine.js')
        .replace(`app.asar${path.sep}`, `app.asar.unpacked${path.sep}`);
}

function settle(message) {
    const waiting = pending.get(message.id);

    if (!waiting) {
        return;
    }

    pending.delete(message.id);

    if (message.ok) {
        waiting.resolve(message.value);
    } else {
        waiting.reject(new Error(message.error ?? 'The torrent client failed'));
    }
}

function stopEngine(reason) {
    for (const waiting of pending.values()) {
        waiting.reject(new Error(reason));
    }

    pending.clear();
    engine = null;
    starting = null;
}

async function ensureEngine() {
    if (engine) {
        return engine;
    }

    if (starting) {
        return starting;
    }

    starting = new Promise((resolve, reject) => {
        const directory = downloadDir();

        fs.mkdirSync(directory, { recursive: true });

        // The download directory is an argument rather than a message: the
        // engine has it before it can be asked to do anything, so there is no
        // window in which a torrent could start and write somewhere else.
        const child = utilityProcess.fork(enginePath(), [directory], {
            serviceName: 'cyberia-torrent',
            stdio: 'inherit',
        });

        child.on('message', (message) => {
            if (message?.event === 'torrents') {
                // The page's own contents, not the BaseWindow that holds it.
                const contents = context.getContents();

                if (contents && !contents.isDestroyed()) {
                    contents.send('torrent:update', message.value);
                }

                return;
            }

            if (message?.event === 'engine-error') {
                console.warn('[cyberia] torrent client:', message.value);

                return;
            }

            settle(message ?? {});
        });

        child.on('exit', (code) => {
            stopEngine('The torrent client stopped');
            reject(new Error(`The torrent client exited (${code})`));
        });

        child.once('spawn', () => {
            engine = child;
            starting = null;
            resolve(child);
        });
    });

    return starting;
}

function call(action, args = []) {
    if (!engine) {
        return Promise.reject(new Error('The torrent client is not running'));
    }

    const id = nextId++;

    return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        engine.postMessage({ id, action, args });
    });
}

/**
 * Ask once, before any socket is opened.
 *
 * A page cannot draw this and cannot answer it. Declining is remembered only
 * for this attempt — someone who says no today is asked again next time they
 * press the button, rather than finding a dead button and no explanation.
 */
async function ensureConsent() {
    if (readConsent()) {
        return true;
    }

    const window = context.getWindow();
    const { response } = await dialog.showMessageBox(window ?? undefined, {
        type: 'warning',
        title: 'Peer-to-peer downloading',
        message: 'Start the BitTorrent client?',
        detail:
            'Peers you connect to see your IP address, and the app’s proxy setting does not cover this traffic — that setting is for web requests, and these are raw sockets.\n\n'
            + `Files are written to ${downloadDir()}.\n\n`
            + 'What you download and share is yours to answer for.',
        buttons: ['Cancel', 'Start the client'],
        defaultId: 1,
        cancelId: 0,
        noLink: true,
    });

    if (response === 1) {
        writeConsent(true);

        return true;
    }

    return false;
}

/**
 * Ask before creating a torrent, which is a different act from downloading one.
 *
 * Downloading is a decision about this machine; seeding publishes a list of
 * files under a name that will be handed to strangers, and the person doing it
 * should have read that sentence at least once.
 */
async function confirmSeed(paths) {
    const window = context.getWindow();
    const { response } = await dialog.showMessageBox(window ?? undefined, {
        type: 'warning',
        title: 'Create a torrent',
        message: `Share ${paths.length === 1 ? 'this' : `these ${paths.length}`} from this computer?`,
        detail:
            'A torrent is made from the files you picked and this computer starts sharing them. '
            + 'Nothing is uploaded to a server — peers connect to you directly and see your IP address, '
            + 'and the app’s proxy setting does not cover that traffic.\n\n'
            + 'The files stay where they are. Sharing stops when you remove the torrent or close the app.',
        buttons: ['Cancel', 'Create and share'],
        defaultId: 1,
        cancelId: 0,
        noLink: true,
    });

    return response === 1;
}

/**
 * The window's answer to `cyberia-media://torrent/<info hash>/<index>`.
 *
 * The page is served over https and a media element on it cannot load
 * `http://127.0.0.1` — that is mixed content, blocked with no visible error.
 * So the shell registers a scheme of its own and this proxies it to the
 * engine's loopback server, forwarding the one header that matters: `Range`,
 * without which seeking does not exist and the player has to buffer a film
 * from the beginning to reach the end.
 *
 * The port is never in a URL the page sees, and the only things addressable
 * through here are files inside torrents this client already holds.
 */
async function streamResponse(request) {
    let target;

    try {
        const url = new URL(request.url);
        const [hash, index] = url.pathname.replace(/^\/+/, '').split('/');

        if (url.hostname !== 'torrent' || !hash) {
            return new Response('Not found', { status: 404 });
        }

        const stream = await call('streamUrl', [hash, Number(index)]);
        target = stream.url;
    } catch (error) {
        return new Response(String(error?.message ?? error), { status: 404 });
    }

    const headers = {};
    const range = request.headers.get('Range');

    if (range) {
        headers.Range = range;
    }

    const upstream = await net.fetch(target, {
        method: request.method === 'HEAD' ? 'HEAD' : 'GET',
        headers,
    });

    // A deliberate subset. The engine's server sets a content security policy
    // meant for its own index pages, and passing that through to a media
    // element is noise at best.
    const passed = new Headers();

    for (const name of ['content-type', 'content-length', 'content-range', 'accept-ranges']) {
        const value = upstream.headers.get(name);

        if (value !== null) {
            passed.set(name, value);
        }
    }

    return new Response(upstream.body, {
        status: upstream.status,
        statusText: upstream.statusText,
        headers: passed,
    });
}

/**
 * Wire the bridge up.
 *
 * `isTrusted` decides which frames may speak to the engine at all: the offline
 * page and any other local page share this preload, and none of them has any
 * business starting a download. `getWindow` is only ever a dialog's parent;
 * `getContents` is where progress is pushed.
 */
function register({ getWindow, getContents, isTrusted, appUrl }) {
    context = { getWindow, getContents, isTrusted, appUrl: String(appUrl ?? '') };

    const guard = (handler) => async (event, ...args) => {
        if (!context.isTrusted(event.senderFrame?.url ?? '')) {
            throw new Error('Not allowed from this page');
        }

        return handler(...args);
    };

    ipcMain.handle(
        'torrent:info',
        guard(async () => ({
            version: ENGINE_VERSION,
            downloadDir: downloadDir(),
            maxReadBytes: MAX_READ_BYTES,
            maxTorrents: MAX_TORRENTS,
            maxSeedFiles: MAX_SEED_FILES,
            announceUrl: announceUrlFor(context.appUrl),
            consented: readConsent(),
        })),
    );

    ipcMain.handle(
        'torrent:add',
        guard(async (source) => {
            if (!(await ensureConsent())) {
                throw new Error('Downloading was not started');
            }

            await ensureEngine();

            return call('add', [source]);
        }),
    );

    // Everything below acts on torrents that already exist, so none of them
    // starts an engine: with nothing running there is nothing to list.
    ipcMain.handle(
        'torrent:list',
        guard(async () => (engine ? call('list') : [])),
    );

    ipcMain.handle('torrent:pause', guard((hash) => call('pause', [hash])));
    ipcMain.handle('torrent:resume', guard((hash) => call('resume', [hash])));
    ipcMain.handle(
        'torrent:remove',
        guard((hash, deleteFiles) => call('remove', [hash, deleteFiles])),
    );
    ipcMain.handle(
        'torrent:read',
        guard((hash, index) => call('read', [hash, index])),
    );

    /**
     * Create a torrent from files on this disk and start seeding it.
     *
     * The page asks for a dialog, not for a path: it says whether it wants
     * files or a folder and the person in front of the machine chooses. That
     * is the whole reason this is here rather than in the page — remote
     * content naming a path is a read primitive, and remote content naming a
     * tracker is an instruction about who this client talks to.
     */
    ipcMain.handle(
        'torrent:seed',
        guard(async (mode) => {
            if (!(await ensureConsent())) {
                throw new Error('Sharing was not started');
            }

            const window = context.getWindow();
            const { canceled, filePaths } = await dialog.showOpenDialog(window ?? undefined, {
                title: mode === 'folder' ? 'Choose a folder to share' : 'Choose files to share',
                properties:
                    mode === 'folder'
                        ? ['openDirectory']
                        : ['openFile', 'multiSelections'],
            });

            if (canceled || filePaths.length === 0) {
                return null;
            }

            if (!(await confirmSeed(filePaths))) {
                return null;
            }

            await ensureEngine();

            return call('seed', [filePaths, announceUrlFor(context.appUrl)]);
        }),
    );

    // Playing from a swarm. What comes back is the shell's own scheme, never
    // the loopback port behind it.
    ipcMain.handle(
        'torrent:stream',
        guard(async (hash, index) => {
            const stream = await call('streamUrl', [hash, index]);

            return {
                url: `${STREAM_SCHEME}://torrent/${encodeURIComponent(String(hash))}/${Number(index)}`,
                name: stream.name,
                length: stream.length,
            };
        }),
    );

    // For the formats no browser has ever decoded — Matroska above all, which
    // is most of what a film in a swarm actually is.
    ipcMain.handle(
        'torrent:openFile',
        guard(async (hash, index) => {
            const target = await call('filePath', [hash, index]);

            await shell.openPath(target);

            return true;
        }),
    );

    ipcMain.handle(
        'torrent:reveal',
        guard(async () => {
            const directory = downloadDir();

            fs.mkdirSync(directory, { recursive: true });
            await shell.openPath(directory);

            return true;
        }),
    );
}

/** Stop the client on quit, so no swarm outlives the window. */
function shutdown() {
    if (engine) {
        engine.kill();
        stopEngine('The app is closing');
    }
}

module.exports = { register, shutdown, streamResponse };
