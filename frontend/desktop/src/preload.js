'use strict';

/**
 * The only bridge between the site and the shell.
 *
 * `window.cyberiaNative` is what the Laravel frontend feature-detects to know
 * it is running inside the desktop app (see resources/js/lib/native.ts); the
 * offline page uses `retry()`.
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
});
