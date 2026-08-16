'use strict';

/**
 * The window frame the shell draws for itself.
 *
 * The app renders a remote site, so the frame cannot live in the page the way
 * VSCode's does: the title bar is a local view of its own (`titlebar.html`) and
 * the site is a second view underneath it, both children of one frameless
 * window. Everything that has to be decided before either view exists lives
 * here — how the two are laid out, which keys the missing native menu bar used
 * to answer, and what the drawn menu bar is allowed to show — so it is testable
 * under plain Node like the navigation rules.
 *
 * `--native-frame` (or `CYBERIA_NATIVE_FRAME=1`) hands the window back to the
 * desktop: a window manager that decorates windows its own way, or a session
 * where a client-side frame cannot be resized, is a real machine and not a
 * hypothetical, and the shell must stay usable on it.
 */

/** Height of the title bar in DIP, border included. */
const TITLEBAR_HEIGHT = 32;

/**
 * Space kept clear on the left on macOS, where the system draws the traffic
 * lights over our view and the menu bar has to start after them.
 */
const MAC_INSET = 78;

/** Traffic lights are 12px tall, so this centres them in a 32px bar. */
const MAC_TRAFFIC_LIGHTS = { x: 13, y: 10 };

/** Values that mean "yes" for `CYBERIA_NATIVE_FRAME`. */
const TRUE_VALUES = new Set(['1', 'true', 'yes', 'on']);

/** How far the page may be zoomed from the menu, in Chromium zoom levels. */
const ZOOM_RANGE = { min: -3, max: 5, step: 0.5 };

/**
 * Every command the frame can run, and how it is spelled in a key stroke.
 *
 * This table is the accelerator list. With a custom frame there is no native
 * menu bar attached to the window on Windows and Linux, so nothing would
 * otherwise answer Ctrl+R — the menus a popup shows still print their
 * accelerator, and a printed accelerator that does nothing is worse than none.
 * macOS keeps its real menu bar at the top of the screen and answers these
 * itself, which is why the shell never installs this handler there.
 *
 * A stroke is matched on the key *or* on the physical position (`code`), the
 * way Chromium matches its own: on a Cyrillic layout Ctrl+R arrives as Ctrl+к,
 * and a shortcut that stops working when the layout is switched is not a
 * shortcut.
 */
const KEY_COMMANDS = [
    { command: 'reload', key: 'f5', code: 'F5' },
    { command: 'fullscreen', key: 'f11', code: 'F11' },
    { command: 'devtools', key: 'f12', code: 'F12' },
    { command: 'force-reload', key: 'f5', code: 'F5', control: true },
    { command: 'back', key: 'arrowleft', code: 'ArrowLeft', alt: true },
    { command: 'forward', key: 'arrowright', code: 'ArrowRight', alt: true },
    { command: 'reload', key: 'r', code: 'KeyR', control: true },
    { command: 'force-reload', key: 'r', code: 'KeyR', control: true, shift: true },
    { command: 'devtools', key: 'i', code: 'KeyI', control: true, shift: true },
    { command: 'wallet', key: 'h', code: 'KeyH', control: true, shift: true },
    { command: 'site', key: 's', code: 'KeyS', control: true, shift: true },
    { command: 'zoom-in', key: '=', code: 'Equal', control: true },
    { command: 'zoom-in', key: '+', code: 'NumpadAdd', control: true },
    { command: 'zoom-in', key: '=', code: 'Equal', control: true, shift: true },
    { command: 'zoom-out', key: '-', code: 'Minus', control: true },
    { command: 'zoom-reset', key: '0', code: 'Digit0', control: true },
    { command: 'quit', key: 'q', code: 'KeyQ', control: true },
];

/** Whether this run draws its own frame. */
function usesCustomFrame(env, argv) {
    if (Array.isArray(argv) && argv.includes('--native-frame')) {
        return false;
    }

    const value = typeof env.CYBERIA_NATIVE_FRAME === 'string'
        ? env.CYBERIA_NATIVE_FRAME.trim().toLowerCase()
        : '';

    return !TRUE_VALUES.has(value);
}

/**
 * Where the two views go inside the window's content area.
 *
 * `titlebar` is 0 whenever the bar is not on screen — full screen, or a run
 * with the native frame — and the site then fills the window on its own. A
 * window shorter than the bar (a fold mid-resize) gives the bar what is left
 * rather than a negative height Electron would refuse.
 */
function frameLayout({ width, height, titlebar = TITLEBAR_HEIGHT }) {
    const area = {
        width: Math.max(0, Math.round(width) || 0),
        height: Math.max(0, Math.round(height) || 0),
    };

    const bar = Math.min(Math.max(0, Math.round(titlebar) || 0), area.height);

    return {
        chrome: { x: 0, y: 0, width: area.width, height: bar },
        content: { x: 0, y: bar, width: area.width, height: area.height - bar },
    };
}

/** The command a key stroke asks for, or `null` if it asks for nothing. */
function commandForInput(input) {
    if (!input || input.type !== 'keyDown' || typeof input.key !== 'string') {
        return null;
    }

    // A Windows/Linux stroke only; anything with the Command key belongs to the
    // native menu bar on the platform that has one.
    if (input.meta) {
        return null;
    }

    const key = input.key.toLowerCase();
    const code = typeof input.code === 'string' ? input.code : '';
    const stroke = {
        control: Boolean(input.control),
        alt: Boolean(input.alt),
        shift: Boolean(input.shift),
    };

    const match = KEY_COMMANDS.find(
        (entry) =>
            (entry.key === key || entry.code === code)
            && Boolean(entry.control) === stroke.control
            && Boolean(entry.alt) === stroke.alt
            && Boolean(entry.shift) === stroke.shift,
    );

    return match ? match.command : null;
}

/** The next zoom level for a zoom command, kept inside a readable range. */
function zoomLevel(current, command) {
    const level = Number.isFinite(current) ? current : 0;

    if (command === 'zoom-reset') {
        return 0;
    }

    const moved = command === 'zoom-in' ? level + ZOOM_RANGE.step : level - ZOOM_RANGE.step;

    return Math.min(ZOOM_RANGE.max, Math.max(ZOOM_RANGE.min, moved));
}

/**
 * The top-level menus the drawn bar may show.
 *
 * Taken from the built application menu rather than from a list of its own, so
 * the bar and the menus that open under it can never disagree. Items with no
 * submenu (a bare command, a separator) are not a menu bar entry, and `&File`
 * is printed as `File` — the ampersand is the native bar's mnemonic marker.
 */
function menuBarItems(menu) {
    const items = menu && Array.isArray(menu.items) ? menu.items : [];

    return items
        .map((item, index) => ({
            index,
            label: typeof item.label === 'string' ? item.label.replace(/&/g, '') : '',
            hasSubmenu: Boolean(item.submenu),
        }))
        .filter((item) => item.label !== '' && item.hasSubmenu)
        .map(({ index, label }) => ({ index, label }));
}

module.exports = {
    MAC_INSET,
    MAC_TRAFFIC_LIGHTS,
    TITLEBAR_HEIGHT,
    ZOOM_RANGE,
    commandForInput,
    frameLayout,
    menuBarItems,
    usesCustomFrame,
    zoomLevel,
};
