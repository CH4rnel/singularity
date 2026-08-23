'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const { test } = require('node:test');
const {
    linuxAutostartEntry,
    linuxAutostartFile,
    linuxExecutable,
    quoteDesktopArgument,
} = require('../src/autostart');

test('the Linux login item starts this app hidden', () => {
    const entry = linuxAutostartEntry('/opt/Cyberia/cyberia-desktop');

    assert.match(entry, /^\[Desktop Entry\]$/m);
    assert.match(
        entry,
        /^Exec="\/opt\/Cyberia\/cyberia-desktop" --autostart$/m,
    );
    assert.match(entry, /^X-Cyberia-Managed=true$/m);
    assert.doesNotMatch(entry, /--no-proxy/);
});

test('portable AppImages restart from their real path', () => {
    assert.equal(
        linuxExecutable(
            { APPIMAGE: '/home/lain/Загрузки/Cyberia wallet.AppImage' },
            '/tmp/.mount_Cyberia/cyberia-desktop',
        ),
        '/home/lain/Загрузки/Cyberia wallet.AppImage',
    );
    assert.equal(
        linuxExecutable({}, '/opt/Cyberia/cyberia-desktop'),
        '/opt/Cyberia/cyberia-desktop',
    );
});

test('desktop Exec arguments cannot break out of their quotes', () => {
    assert.equal(
        quoteDesktopArgument('/tmp/a "wallet" `$HOME`.AppImage\nignored'),
        '"/tmp/a \\"wallet\\" \\`\\$HOME\\`.AppImageignored"',
    );
});

test('the launcher lives beside the app config, under autostart', () => {
    assert.equal(
        linuxAutostartFile('/home/alice/.config/Cyberia'),
        path.join('/home/alice/.config', 'autostart', 'Cyberia.desktop'),
    );
});
