'use strict';

/**
 * The only bridge between the site and the shell.
 *
 * `window.cyberiaNative` is what the Laravel frontend feature-detects to know
 * it is running inside the desktop app (see resources/js/lib/native.ts); the
 * offline page uses `retry()` and `openProxySettings()`.
 *
 * Nothing here changes a setting. `openProxySettings()` raises a window the
 * user then has to act in, so the worst a hostile page could do with it is
 * annoy; the proxy itself is readable and writable only from that window's own
 * preload (`preload-proxy.js`), which no remote content is ever loaded into.
 *
 * `torrent` is the one capability a web page genuinely cannot have on its own:
 * the mainline DHT is UDP and peers are TCP, so a real client needs this
 * process. Every method is a message to the main process, which decides what
 * may happen — the page can name a magnet and a file index, never a path.
 */

const { contextBridge, ipcRenderer } = require('electron');

const info = ipcRenderer.sendSync('shell:info');

/** Progress is pushed from the engine; listeners live only in this scope. */
const torrentListeners = new Set();

ipcRenderer.on('torrent:update', (_event, torrents) => {
    for (const listener of torrentListeners) {
        listener(torrents);
    }
});

contextBridge.exposeInMainWorld('cyberiaNative', {
    shell: info.shell,
    platform: info.platform,
    version: info.version,
    url: info.url,
    proxy: info.proxy,
    tray: info.tray === true,
    retry: () => ipcRenderer.send('shell:retry'),
    openExternal: (url) => ipcRenderer.send('shell:open-external', url),
    openProxySettings: () => ipcRenderer.send('shell:open-proxy'),
    startup: {
        available: Boolean(info.startup?.available),
        enabled: Boolean(info.startup?.enabled),
        get: () => ipcRenderer.invoke('shell:get-startup'),
        set: (enabled) =>
            ipcRenderer.invoke('shell:set-startup', Boolean(enabled)),
    },
    torrent: {
        version: 1,
        info: () => ipcRenderer.invoke('torrent:info'),
        add: (source) => ipcRenderer.invoke('torrent:add', String(source)),
        list: () => ipcRenderer.invoke('torrent:list'),
        pause: (infoHash) => ipcRenderer.invoke('torrent:pause', String(infoHash)),
        resume: (infoHash) => ipcRenderer.invoke('torrent:resume', String(infoHash)),
        remove: (infoHash, deleteFiles) =>
            ipcRenderer.invoke('torrent:remove', String(infoHash), Boolean(deleteFiles)),
        read: (infoHash, fileIndex) =>
            ipcRenderer.invoke('torrent:read', String(infoHash), Number(fileIndex)),
        reveal: () => ipcRenderer.invoke('torrent:reveal'),
        subscribe: (listener) => {
            torrentListeners.add(listener);

            return () => torrentListeners.delete(listener);
        },
    },
});
