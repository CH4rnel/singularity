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
const { app, BrowserWindow, Menu, dialog, ipcMain, session, shell } = require('electron');
const {
    PARTITION,
    PROTOCOL,
    describeProxy,
    isExternallyOpenable,
    isNavigable,
    resolveAppUrl,
    resolveProxy,
    resolveStartUrl,
} = require('./config');
const { loadWindowState, trackWindowState } = require('./window-state');

const APP_URL = resolveAppUrl(process.env, process.argv);
const START_URL = resolveStartUrl(process.env, process.argv);
const APP_HOST = new URL(APP_URL).hostname;
const PROXY = resolveProxy(process.env, process.argv);
const OFFLINE_PAGE = path.join(__dirname, 'offline.html');

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
        query: { url: START_URL, error, proxy: describeProxy(PROXY) },
    });
}

async function configureSession() {
    const shellSession = session.fromPartition(PARTITION);

    // Pinned before the first load: on Linux desktops Chromium takes its proxy
    // from the desktop settings and ignores http_proxy/https_proxy, so a stale
    // system entry would fail every request with ERR_PROXY_CONNECTION_FAILED.
    if (PROXY) {
        await Promise.all([shellSession.setProxy(PROXY), session.defaultSession.setProxy(PROXY)]);
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

        console.log(`[cyberia] ${START_URL} via proxy ${describeProxy(PROXY)}`);

        ipcMain.on('shell:retry', () => loadApp());
        ipcMain.on('shell:open-external', (_event, url) => openExternal(url));
        ipcMain.on('shell:info', (event) => {
            event.returnValue = {
                shell: 'desktop',
                platform: process.platform,
                version: app.getVersion(),
                url: START_URL,
                proxy: describeProxy(PROXY),
            };
        });

        createWindow();
        handleDeepLink(deepLinkFrom(process.argv));

        app.on('activate', () => {
            if (BrowserWindow.getAllWindows().length === 0) {
                createWindow();
            }
        });
    });

    app.on('window-all-closed', () => {
        if (process.platform !== 'darwin') {
            app.quit();
        }
    });
}
