'use strict';

/**
 * Bridge for the proxy window, and for nothing else.
 *
 * `src/proxy.html` is a local file the shell loads itself, so this is the one
 * renderer that may read and change how the app reaches the network. The site's
 * preload deliberately has none of it.
 */

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('cyberiaProxy', {
    state: () => ipcRenderer.invoke('proxy:state'),
    apply: (setting) => ipcRenderer.invoke('proxy:apply', setting),
    close: () => ipcRenderer.send('proxy:close'),
});
