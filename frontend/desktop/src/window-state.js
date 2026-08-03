'use strict';

/**
 * Remembers the window geometry between runs.
 *
 * Saved bounds are re-validated against the displays that exist right now, so
 * unplugging a second monitor can never park the window off-screen.
 */

const fs = require('node:fs');
const path = require('node:path');
const { app, screen } = require('electron');

const DEFAULT_STATE = {
    width: 1280,
    height: 860,
    maximized: false,
};

const SAVE_DEBOUNCE_MS = 400;

function stateFile() {
    return path.join(app.getPath('userData'), 'window-state.json');
}

function isVisibleOnSomeDisplay(bounds) {
    return screen.getAllDisplays().some((display) => {
        const area = display.workArea;

        return (
            bounds.x < area.x + area.width &&
            bounds.x + bounds.width > area.x &&
            bounds.y < area.y + area.height &&
            bounds.y + bounds.height > area.y
        );
    });
}

function loadWindowState() {
    let stored;

    try {
        stored = JSON.parse(fs.readFileSync(stateFile(), 'utf8'));
    } catch {
        return { ...DEFAULT_STATE };
    }

    const numbers = ['width', 'height', 'x', 'y'].every(
        (key) => stored[key] === undefined || Number.isFinite(stored[key]),
    );

    if (!numbers || !Number.isFinite(stored.width) || !Number.isFinite(stored.height)) {
        return { ...DEFAULT_STATE };
    }

    const state = {
        width: Math.max(380, Math.round(stored.width)),
        height: Math.max(520, Math.round(stored.height)),
        maximized: Boolean(stored.maximized),
    };

    if (Number.isFinite(stored.x) && Number.isFinite(stored.y)) {
        const bounds = {
            x: Math.round(stored.x),
            y: Math.round(stored.y),
            width: state.width,
            height: state.height,
        };

        if (isVisibleOnSomeDisplay(bounds)) {
            state.x = bounds.x;
            state.y = bounds.y;
        }
    }

    return state;
}

function trackWindowState(window) {
    let timer = null;

    const save = () => {
        if (window.isDestroyed()) {
            return;
        }

        const bounds = window.isMaximized() || window.isFullScreen()
            ? window.getNormalBounds()
            : window.getBounds();

        const payload = {
            ...bounds,
            maximized: window.isMaximized(),
        };

        try {
            fs.writeFileSync(stateFile(), JSON.stringify(payload, null, 2));
        } catch {
            // Losing the geometry is never worth interrupting the user.
        }
    };

    const scheduleSave = () => {
        clearTimeout(timer);
        timer = setTimeout(save, SAVE_DEBOUNCE_MS);
    };

    window.on('resize', scheduleSave);
    window.on('move', scheduleSave);
    window.on('close', () => {
        clearTimeout(timer);
        save();
    });
}

module.exports = { loadWindowState, trackWindowState };
