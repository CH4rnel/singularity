'use strict';

/**
 * Cyberia desktop shell.
 *
 * The app is the Cyberia wallet: the window opens on `/wallet`, which renders
 * without the site chrome inside a native shell, and the rest of Cyberia is
 * reachable from inside it. Everything is still the live site inside a
 * persistent session, so a login survives restarts exactly like in a browser.
 *
 * Anything that is not Cyberia (wallet deep links, explorer links,
 * `target="_blank"`) is handed to the system browser instead of being opened
 * inside the app frame.
 */

const path = require('node:path');
const { app, BrowserWindow, Menu, dialog, ipcMain, net, session, shell } = require('electron');
const {
    PARTITION,
    PROTOCOL,
    describeProxy,
    hasProxyFlag,
    isExternallyOpenable,
    isNavigable,
    normalizeProxySetting,
    resolveAppUrl,
    resolveProxyDecision,
    resolveStartUrl,
} = require('./config');
const { loadProxySetting, saveProxySetting } = require('./proxy-settings');
const torrent = require('./torrent');
const { loadWindowState, trackWindowState } = require('./window-state');

const APP_URL = resolveAppUrl(process.env, process.argv);
const START_URL = resolveStartUrl(process.env, process.argv);
const APP_HOST = new URL(APP_URL).hostname;
const OFFLINE_PAGE = path.join(__dirname, 'offline.html');
const PROXY_PAGE = path.join(__dirname, 'proxy.html');

/** A launch flag pins the proxy for this run, so the window cannot change it. */
const PROXY_LOCKED = hasProxyFlag(process.argv);

/**
 * How long a new proxy gets to answer before it counts as unreachable. Long
 * enough for a tunnel abroad to hand-shake, short enough that a dead port does
 * not look like a frozen window.
 */
const PROBE_TIMEOUT_MS = 12000;

/** Permissions the site legitimately needs; everything else is refused. */
const ALLOWED_PERMISSIONS = new Set([
    'background-sync',
    'clipboard-sanitized-write',
    'fullscreen',
    'notifications',
]);

/** `did-fail-load` fires with this code for navigations we cancelled ourselves. */
const ERR_ABORTED = -3;

let mainWindow = null;
let proxyWindow = null;
let shellSession = null;

/** The proxy in force right now, what the user saved, and where it came from. */
let activeProxy = null;
let proxySetting = { mode: 'system', server: '' };
let proxySource = 'system';

function buildUserAgent() {
    return `${app.userAgentFallback}`
        .replace(/\sElectron\/\S+/, '')
        .replace(/\scyberia-desktop\/\S+/i, '')
        .replace(/\sCyberia\/\S+/, '')
        .concat(` CyberiaDesktop/${app.getVersion()}`);
}

function openExternal(target) {
    // Wallets and Telegram use custom schemes (wc:, metamask:, tg:), so the
    // shell only screens out the schemes that execute or read something.
    if (!isExternallyOpenable(target)) {
        return;
    }

    void shell.openExternal(target);
}

function loadApp(target = START_URL) {
    if (!mainWindow || mainWindow.isDestroyed()) {
        return;
    }

    void mainWindow.loadURL(target);
}

function showOffline(error = '') {
    if (!mainWindow || mainWindow.isDestroyed()) {
        return;
    }

    void mainWindow.loadFile(OFFLINE_PAGE, {
        query: { url: START_URL, error, proxy: describeProxy(activeProxy) },
    });
}

/**
 * Pins a proxy onto both sessions.
 *
 * Pinned rather than inherited: on Linux desktops Chromium takes its proxy from
 * the desktop settings and ignores http_proxy/https_proxy, so a stale system
 * entry there would fail every request with ERR_PROXY_CONNECTION_FAILED. `null`
 * hands the session back to that system configuration, which is what makes
 * "System" in the proxy window an actual choice rather than a no-op.
 */
async function pinProxy(proxy) {
    const configuration = proxy ?? { mode: 'system' };

    await Promise.all([
        shellSession.setProxy(configuration),
        session.defaultSession.setProxy(configuration),
    ]);

    activeProxy = proxy;
}

/**
 * Asks the site for its headers through whatever proxy is pinned right now.
 *
 * This is what turns the proxy window from a text field into an answer: a
 * setting is only saved once it has actually carried a request, so nobody is
 * left staring at the offline page wondering whether they typed the port wrong
 * or the tunnel is down. A response the site itself sent — even a 404 — proves
 * the connection; a 5xx is the proxy talking about a hop it could not make.
 */
function probeConnection(target) {
    return new Promise((resolve) => {
        let settled = false;

        const request = net.request({
            method: 'HEAD',
            url: target,
            session: shellSession,
            useSessionCookies: false,
        });

        const finish = (result) => {
            if (settled) {
                return;
            }

            settled = true;
            clearTimeout(timer);
            resolve(result);
        };

        const timer = setTimeout(() => {
            request.abort();
            finish({ ok: false, error: 'ERR_CONNECTION_TIMED_OUT' });
        }, PROBE_TIMEOUT_MS);

        request.on('response', (response) => {
            response.on('data', () => {});
            response.on('end', () => {});

            finish(
                response.statusCode >= 500
                    ? { ok: false, error: `HTTP ${response.statusCode}` }
                    : { ok: true, error: '' },
            );
        });

        request.on('error', (error) => finish({ ok: false, error: error.message }));
        request.end();
    });
}

function proxyState() {
    return {
        mode: proxySetting.mode,
        server: proxySetting.server,
        source: proxySource,
        effective: describeProxy(activeProxy),
        url: START_URL,
        locked: PROXY_LOCKED,
    };
}

/**
 * Applies a setting from the proxy window, keeping it only if it connects.
 *
 * A proxy that does not answer would otherwise be saved over the one that did
 * and survive the restart, leaving the app permanently offline with its only
 * settings window behind the same broken connection.
 */
async function applyProxySetting(payload) {
    if (PROXY_LOCKED) {
        return { ok: false, error: 'locked' };
    }

    const requested = normalizeProxySetting(payload);
    const wanted = payload && typeof payload === 'object' ? String(payload.mode ?? '') : '';

    // A manual entry Chromium cannot use normalises to "system"; silently
    // connecting directly would read as "it worked" for a typed-in typo.
    if (wanted.toLowerCase() === 'manual' && requested.mode !== 'manual') {
        return { ok: false, error: 'invalid' };
    }

    const previous = activeProxy;
    const decision = resolveProxyDecision(process.env, process.argv, requested);

    await pinProxy(decision.proxy);

    const probe = await probeConnection(START_URL);

    if (!probe.ok) {
        await pinProxy(previous);

        return { ok: false, error: probe.error };
    }

    proxySetting = saveProxySetting(requested);
    proxySource = decision.source;

    loadApp();

    return { ok: true, error: '', ...proxyState() };
}

function openProxyWindow() {
    if (proxyWindow && !proxyWindow.isDestroyed()) {
        proxyWindow.focus();

        return;
    }

    proxyWindow = new BrowserWindow({
        width: 460,
        // Tall enough for the three choices, a wrapped failure and the line
        // saying what is in force — the window never has to scroll to answer.
        height: 700,
        parent: mainWindow ?? undefined,
        modal: Boolean(mainWindow),
        show: false,
        resizable: false,
        minimizable: false,
        maximizable: false,
        backgroundColor: '#0b0f10',
        title: 'Cyberia — Proxy',
        autoHideMenuBar: true,
        webPreferences: {
            // Its own preload: reading and changing the proxy is a power the
            // remote site must not be handed along with the rest of the bridge.
            preload: path.join(__dirname, 'preload-proxy.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
        },
    });

    proxyWindow.once('ready-to-show', () => proxyWindow.show());
    proxyWindow.on('closed', () => {
        proxyWindow = null;
    });

    void proxyWindow.loadFile(PROXY_PAGE);
}

async function configureSession() {
    shellSession = session.fromPartition(PARTITION);

    proxySetting = loadProxySetting();

    const decision = resolveProxyDecision(process.env, process.argv, proxySetting);

    proxySource = decision.source;

    if (decision.proxy) {
        await pinProxy(decision.proxy);
    }

    shellSession.setPermissionRequestHandler((_contents, permission, callback) => {
        callback(ALLOWED_PERMISSIONS.has(permission));
    });

    shellSession.setPermissionCheckHandler((_contents, permission) =>
        ALLOWED_PERMISSIONS.has(permission),
    );

    // Hardware wallets over WebHID/WebUSB are not wired up yet; refuse the
    // device pickers rather than showing an empty chooser.
    shellSession.setDevicePermissionHandler(() => false);

    return shellSession;
}

function createWindow() {
    const state = loadWindowState();

    mainWindow = new BrowserWindow({
        x: state.x,
        y: state.y,
        width: state.width,
        height: state.height,
        minWidth: 380,
        minHeight: 520,
        show: false,
        backgroundColor: '#0b0f10',
        title: 'Cyberia',
        autoHideMenuBar: true,
        icon: process.platform === 'linux'
            ? path.join(__dirname, '..', 'build', 'icon.png')
            : undefined,
        webPreferences: {
            partition: PARTITION,
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            spellcheck: true,
        },
    });

    if (state.maximized) {
        mainWindow.maximize();
    }

    trackWindowState(mainWindow);

    mainWindow.once('ready-to-show', () => mainWindow.show());

    const contents = mainWindow.webContents;

    contents.setWindowOpenHandler(({ url }) => {
        openExternal(url);

        return { action: 'deny' };
    });

    contents.on('will-navigate', (event, url) => {
        if (isNavigable(url, APP_HOST) || url.startsWith('file://')) {
            return;
        }

        event.preventDefault();
        openExternal(url);
    });

    contents.on('did-fail-load', (_event, errorCode, description, _url, isMainFrame) => {
        if (isMainFrame && errorCode !== ERR_ABORTED) {
            showOffline(description);
        }
    });

    contents.on('render-process-gone', (_event, details) => {
        if (details.reason !== 'clean-exit') {
            loadApp();
        }
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    loadApp();
}

function focusWindow() {
    if (!mainWindow || mainWindow.isDestroyed()) {
        createWindow();

        return;
    }

    if (mainWindow.isMinimized()) {
        mainWindow.restore();
    }

    mainWindow.focus();
}

/** `cyberia://profile?tab=xp` -> `<APP_URL>/profile?tab=xp`. */
function handleDeepLink(target) {
    if (typeof target !== 'string' || !target.startsWith(`${PROTOCOL}://`)) {
        return;
    }

    let url;

    try {
        url = new URL(target);
    } catch {
        return;
    }

    const route = `${url.host}${url.pathname}`.replace(/^\/*/, '/');

    focusWindow();
    loadApp(`${APP_URL}${route}${url.search}${url.hash}`);
}

function deepLinkFrom(argv) {
    return argv.find((argument) => argument.startsWith(`${PROTOCOL}://`)) ?? null;
}

function buildMenu() {
    const isMac = process.platform === 'darwin';

    const template = [
        ...(isMac ? [{ role: 'appMenu' }] : []),
        {
            label: 'File',
            submenu: [
                {
                    label: 'Wallet',
                    accelerator: 'CmdOrCtrl+Shift+H',
                    click: () => loadApp(),
                },
                {
                    label: 'Cyberia Site',
                    accelerator: 'CmdOrCtrl+Shift+S',
                    click: () => loadApp(APP_URL),
                },
                {
                    label: 'Open in Browser',
                    click: () => openExternal(mainWindow?.webContents.getURL() ?? START_URL),
                },
                { type: 'separator' },
                {
                    label: 'Proxy…',
                    click: () => openProxyWindow(),
                },
                { type: 'separator' },
                isMac ? { role: 'close' } : { role: 'quit' },
            ],
        },
        { role: 'editMenu' },
        {
            label: 'View',
            submenu: [
                {
                    label: 'Back',
                    accelerator: 'Alt+Left',
                    click: () => mainWindow?.webContents.navigationHistory.goBack(),
                },
                {
                    label: 'Forward',
                    accelerator: 'Alt+Right',
                    click: () => mainWindow?.webContents.navigationHistory.goForward(),
                },
                { role: 'reload' },
                { role: 'forceReload' },
                { type: 'separator' },
                { role: 'resetZoom' },
                { role: 'zoomIn' },
                { role: 'zoomOut' },
                { type: 'separator' },
                { role: 'togglefullscreen' },
                { role: 'toggleDevTools' },
            ],
        },
        { role: 'windowMenu' },
        {
            role: 'help',
            submenu: [
                {
                    label: 'About Cyberia',
                    click: () => {
                        void dialog.showMessageBox(mainWindow ?? undefined, {
                            type: 'info',
                            title: 'Cyberia',
                            message: `Cyberia ${app.getVersion()}`,
                            detail: `Electron ${process.versions.electron}\n${START_URL}`,
                            buttons: ['OK'],
                        });
                    },
                },
            ],
        },
    ];

    Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

function registerProtocol() {
    if (process.defaultApp && process.argv.length >= 2) {
        app.setAsDefaultProtocolClient(PROTOCOL, process.execPath, [path.resolve(process.argv[1])]);

        return;
    }

    app.setAsDefaultProtocolClient(PROTOCOL);
}

if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', (_event, argv) => {
        focusWindow();
        handleDeepLink(deepLinkFrom(argv));
    });

    app.on('open-url', (event, url) => {
        event.preventDefault();
        handleDeepLink(url);
    });

    // Before `whenReady`, so `app.getPath('userData')` resolves to the same
    // directory in a dev run as in the packaged app.
    app.setName('Cyberia');

    app.whenReady().then(async () => {
        app.userAgentFallback = buildUserAgent();

        registerProtocol();
        await configureSession();
        buildMenu();

        console.log(
            `[cyberia] ${START_URL} via proxy ${describeProxy(activeProxy)} (${proxySource})`,
        );

        ipcMain.on('shell:retry', () => loadApp());
        ipcMain.on('shell:open-external', (_event, url) => openExternal(url));
        // Opening the window is all the site (and the offline page) may do:
        // reading and changing the proxy lives behind the window's own preload.
        ipcMain.on('shell:open-proxy', () => openProxyWindow());
        ipcMain.on('shell:info', (event) => {
            event.returnValue = {
                shell: 'desktop',
                platform: process.platform,
                version: app.getVersion(),
                url: START_URL,
                proxy: describeProxy(activeProxy),
            };
        });

        // The torrent client. Only the site's own pages may reach it, and it
        // stays unstarted until someone agrees to run one in a dialog no page
        // can draw.
        torrent.register({
            getWindow: () => mainWindow,
            isTrusted: (url) => isNavigable(url, APP_HOST),
        });

        ipcMain.handle('proxy:state', () => proxyState());
        ipcMain.handle('proxy:apply', (_event, setting) => applyProxySetting(setting));
        ipcMain.on('proxy:close', () => {
            if (proxyWindow && !proxyWindow.isDestroyed()) {
                proxyWindow.close();
            }
        });

        createWindow();
        handleDeepLink(deepLinkFrom(process.argv));

        app.on('activate', () => {
            if (BrowserWindow.getAllWindows().length === 0) {
                createWindow();
            }
        });
    });

    // No swarm outlives the window: the client is stopped before the app is.
    app.on('before-quit', () => torrent.shutdown());

    app.on('window-all-closed', () => {
        if (process.platform !== 'darwin') {
            app.quit();
        }
    });
}
