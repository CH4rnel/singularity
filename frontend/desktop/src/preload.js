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
 */

const { contextBridge, ipcRenderer } = require('electron');

const info = ipcRenderer.sendSync('shell:info');

contextBridge.exposeInMainWorld('cyberiaNative', {
    shell: info.shell,
    platform: info.platform,
    version: info.version,
    url: info.url,
    proxy: info.proxy,
    retry: () => ipcRenderer.send('shell:retry'),
    openExternal: (url) => ipcRenderer.send('shell:open-external', url),
    openProxySettings: () => ipcRenderer.send('shell:open-proxy'),
});
