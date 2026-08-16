'use strict';

/**
 * Bridge for the title bar, and for nothing else.
 *
 * `src/titlebar.html` is a local file the shell loads into a view of its own,
 * so this preload is never reached by the site: the page in the view below has
 * `preload.js`, which has none of this. The commands here are the ones a window
 * frame has always been able to run — minimise, maximise, close, the menus —
 * and the main process still checks every name it is handed.
 */

const { contextBridge, ipcRenderer } = require('electron');

const listeners = new Set();

ipcRenderer.on('frame:update', (_event, patch) => {
    for (const listener of listeners) {
        listener(patch);
    }
});

contextBridge.exposeInMainWorld('cyberiaFrame', {
    state: () => ipcRenderer.invoke('frame:state'),
    command: (id) => ipcRenderer.send('frame:command', String(id)),
    menu: (index, x, y) =>
        ipcRenderer.send('frame:menu', Number(index), Number(x), Number(y)),
    subscribe: (listener) => {
        listeners.add(listener);

        return () => listeners.delete(listener);
    },
});
