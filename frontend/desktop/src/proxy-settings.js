'use strict';

/**
 * The proxy the user chose inside the app.
 *
 * Everything else the shell knows about proxies comes from a command line or an
 * environment — neither of which exists for someone who launches Cyberia from a
 * desktop icon. On a network that blocks the site, that person sees the offline
 * page and has no lever at all, which is what this file is for: one small
 * record next to the window geometry in `userData`, written by the proxy window
 * and read before the first load.
 */

const fs = require('node:fs');
const path = require('node:path');
const { app } = require('electron');
const { normalizeProxySetting } = require('./config');

function settingsFile() {
    return path.join(app.getPath('userData'), 'proxy.json');
}

/** Always a usable setting: an unreadable or nonsensical file reads as `system`. */
function loadProxySetting() {
    try {
        return normalizeProxySetting(JSON.parse(fs.readFileSync(settingsFile(), 'utf8')));
    } catch {
        return normalizeProxySetting(null);
    }
}

/**
 * Persists a setting and hands back the normalised form that was written.
 *
 * A failed write is not reported: the proxy has already been applied to the
 * live session by the time this is called, and a connection that works now but
 * is forgotten on the next start still beats refusing to connect at all.
 */
function saveProxySetting(setting) {
    const normalized = normalizeProxySetting(setting);

    try {
        fs.writeFileSync(settingsFile(), JSON.stringify(normalized, null, 2));
    } catch {
        // See above.
    }

    return normalized;
}

module.exports = { loadProxySetting, saveProxySetting };
