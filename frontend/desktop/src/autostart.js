'use strict';

/**
 * Login-startup integration.
 *
 * Electron owns this setting on macOS and Windows. Linux desktops converge on
 * one small freedesktop entry under ~/.config/autostart, so the portable
 * AppImage and the installed .deb can use the same path without a dependency.
 */

const fs = require('node:fs');
const path = require('node:path');

const AUTOSTART_FLAG = '--autostart';
const MANAGED_MARKER = 'X-Cyberia-Managed=true';

/** Quote one argument under the Desktop Entry Exec field grammar. */
function quoteDesktopArgument(value) {
    const clean = String(value).replace(/[\r\n]/g, '');

    return `"${clean.replace(/[\\"`$]/g, '\\$&')}"`;
}

function linuxAutostartFile(userData) {
    return path.join(path.dirname(userData), 'autostart', 'Cyberia.desktop');
}

function linuxAutostartEntry(executable) {
    return [
        '[Desktop Entry]',
        'Type=Application',
        'Version=1.0',
        'Name=Cyberia',
        'Comment=Cyberia wallet',
        `Exec=${quoteDesktopArgument(executable)} ${AUTOSTART_FLAG}`,
        'Icon=Cyberia',
        'Terminal=false',
        'Categories=Network;Finance;',
        'X-GNOME-Autostart-enabled=true',
        MANAGED_MARKER,
        '',
    ].join('\n');
}

function linuxExecutable(env, execPath) {
    return typeof env.APPIMAGE === 'string' && env.APPIMAGE.trim() !== ''
        ? env.APPIMAGE.trim()
        : execPath;
}

/**
 * A small uniform API for the renderer-facing setting.
 *
 * Development runs are deliberately unavailable: registering the Electron
 * development binary alone would start no app and leave a broken login item.
 */
function createAutostart(app, options = {}) {
    const platform = options.platform ?? process.platform;
    const env = options.env ?? process.env;
    const execPath = options.execPath ?? process.execPath;
    const packaged = options.packaged ?? app.isPackaged;
    const supported = ['darwin', 'linux', 'win32'].includes(platform);
    const available = Boolean(packaged && supported);
    const executable = linuxExecutable(env, execPath);
    const linuxFile = linuxAutostartFile(app.getPath('userData'));

    const state = () => {
        if (!available) {
            return { available: false, enabled: false };
        }

        if (platform === 'linux') {
            try {
                const entry = fs.readFileSync(linuxFile, 'utf8');
                const expectedExec = `Exec=${quoteDesktopArgument(executable)} ${AUTOSTART_FLAG}`;
                const disabled =
                    /^(Hidden=true|X-GNOME-Autostart-enabled=false)$/m.test(
                        entry,
                    );

                return {
                    available: true,
                    enabled:
                        entry.includes(MANAGED_MARKER) &&
                        entry.includes(expectedExec) &&
                        !disabled,
                };
            } catch {
                return { available: true, enabled: false };
            }
        }

        const settings =
            platform === 'win32'
                ? app.getLoginItemSettings({
                      path: execPath,
                      args: [AUTOSTART_FLAG],
                  })
                : app.getLoginItemSettings();

        return { available: true, enabled: settings.openAtLogin };
    };

    const set = (enabled) => {
        if (!available) {
            return state();
        }

        if (platform === 'linux') {
            if (enabled) {
                fs.mkdirSync(path.dirname(linuxFile), { recursive: true });

                const temporary = `${linuxFile}.${process.pid}.tmp`;

                try {
                    fs.writeFileSync(
                        temporary,
                        linuxAutostartEntry(executable),
                        {
                            mode: 0o644,
                        },
                    );
                    fs.renameSync(temporary, linuxFile);
                } catch (error) {
                    try {
                        fs.unlinkSync(temporary);
                    } catch {
                        // Nothing was left behind, or the original error already says why.
                    }

                    throw error;
                }
            } else {
                try {
                    const entry = fs.readFileSync(linuxFile, 'utf8');

                    // Never delete a launcher another program happens to own.
                    if (entry.includes(MANAGED_MARKER)) {
                        fs.unlinkSync(linuxFile);
                    }
                } catch (error) {
                    if (error && error.code !== 'ENOENT') {
                        throw error;
                    }
                }
            }

            return state();
        }

        app.setLoginItemSettings({
            openAtLogin: Boolean(enabled),
            ...(platform === 'win32'
                ? { path: execPath, args: [AUTOSTART_FLAG] }
                : {}),
        });

        return state();
    };

    const wasOpenedAtLogin = () =>
        available && platform === 'darwin'
            ? app.getLoginItemSettings().wasOpenedAtLogin
            : process.argv.includes(AUTOSTART_FLAG);

    return { state, set, wasOpenedAtLogin };
}

module.exports = {
    AUTOSTART_FLAG,
    MANAGED_MARKER,
    createAutostart,
    linuxAutostartEntry,
    linuxAutostartFile,
    linuxExecutable,
    quoteDesktopArgument,
};
