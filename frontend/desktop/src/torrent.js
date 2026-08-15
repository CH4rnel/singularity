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
const { app, dialog, ipcMain, shell, utilityProcess } = require('electron');
const {
    ENGINE_VERSION,
    MAX_READ_BYTES,
    MAX_TORRENTS,
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
let context = { getWindow: () => null, isTrusted: () => false };

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
                const window = context.getWindow();

                if (window && !window.isDestroyed()) {
                    window.webContents.send('torrent:update', message.value);
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
 * Wire the bridge up.
 *
 * `isTrusted` decides which frames may speak to the engine at all: the offline
 * page and any other local page share this preload, and none of them has any
 * business starting a download.
 */
function register({ getWindow, isTrusted }) {
    context = { getWindow, isTrusted };

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

module.exports = { register, shutdown };
