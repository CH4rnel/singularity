'use strict';

/**
 * The custom window frame's rules, tested under plain Node like the navigation
 * ones — no display, no Electron binary, no window.
 *
 * Two of these carry weight beyond tidiness: the layout (the site's view is
 * placed by hand, and an arithmetic slip there is a strip of the wallet hidden
 * under the title bar) and the key table (with a custom frame there is no menu
 * bar left to answer Ctrl+R, so this *is* the accelerator list).
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');
const {
    TITLEBAR_HEIGHT,
    commandForInput,
    frameLayout,
    menuBarItems,
    usesCustomFrame,
    zoomLevel,
} = require('../src/frame');

const keyDown = (key, modifiers = {}) => ({ type: 'keyDown', key, ...modifiers });

test('the app draws its own frame unless it is told not to', () => {
    assert.equal(usesCustomFrame({}, []), true);
    assert.equal(usesCustomFrame({}, ['--native-frame']), false);
    assert.equal(usesCustomFrame({ CYBERIA_NATIVE_FRAME: '1' }, []), false);
    assert.equal(usesCustomFrame({ CYBERIA_NATIVE_FRAME: ' Yes ' }, []), false);
    assert.equal(usesCustomFrame({ CYBERIA_NATIVE_FRAME: '0' }, []), true);
});

test('the site gets the window minus the title bar', () => {
    const layout = frameLayout({ width: 1280, height: 860, titlebar: TITLEBAR_HEIGHT });

    assert.deepEqual(layout.chrome, { x: 0, y: 0, width: 1280, height: TITLEBAR_HEIGHT });
    assert.deepEqual(layout.content, {
        x: 0,
        y: TITLEBAR_HEIGHT,
        width: 1280,
        height: 860 - TITLEBAR_HEIGHT,
    });
});

test('a hidden title bar gives the site the whole window', () => {
    const layout = frameLayout({ width: 1280, height: 860, titlebar: 0 });

    assert.deepEqual(layout.chrome, { x: 0, y: 0, width: 1280, height: 0 });
    assert.deepEqual(layout.content, { x: 0, y: 0, width: 1280, height: 860 });
});

test('a window shorter than the bar never asks for a negative height', () => {
    const layout = frameLayout({ width: 400, height: 20 });

    assert.deepEqual(layout.chrome, { x: 0, y: 0, width: 400, height: 20 });
    assert.deepEqual(layout.content, { x: 0, y: 20, width: 400, height: 0 });
});

test('the strokes the missing menu bar used to answer', () => {
    assert.equal(commandForInput(keyDown('r', { control: true })), 'reload');
    assert.equal(commandForInput(keyDown('R', { control: true, shift: true })), 'force-reload');
    assert.equal(commandForInput(keyDown('F5')), 'reload');
    assert.equal(commandForInput(keyDown('F5', { control: true })), 'force-reload');
    assert.equal(commandForInput(keyDown('F11')), 'fullscreen');
    assert.equal(commandForInput(keyDown('I', { control: true, shift: true })), 'devtools');
    assert.equal(commandForInput(keyDown('ArrowLeft', { alt: true })), 'back');
    assert.equal(commandForInput(keyDown('ArrowRight', { alt: true })), 'forward');
    assert.equal(commandForInput(keyDown('H', { control: true, shift: true })), 'wallet');
    assert.equal(commandForInput(keyDown('S', { control: true, shift: true })), 'site');
    assert.equal(commandForInput(keyDown('q', { control: true })), 'quit');
    assert.equal(commandForInput(keyDown('0', { control: true })), 'zoom-reset');
    assert.equal(commandForInput(keyDown('+', { control: true })), 'zoom-in');
    assert.equal(commandForInput(keyDown('-', { control: true })), 'zoom-out');
});

test('a shortcut survives the keyboard layout', () => {
    // Ctrl+Shift+H on a Cyrillic layout: the letter is different, the key is
    // the same one under the same finger.
    assert.equal(
        commandForInput(keyDown('Р', { code: 'KeyH', control: true, shift: true })),
        'wallet',
    );
    assert.equal(
        commandForInput(keyDown('к', { code: 'KeyR', control: true })),
        'reload',
    );
});

test('a stroke nobody claimed is left to the page', () => {
    assert.equal(commandForInput(keyDown('r')), null);
    assert.equal(commandForInput(keyDown('a', { control: true })), null);
    assert.equal(commandForInput({ type: 'keyUp', key: 'F5' }), null);
    assert.equal(commandForInput(null), null);
    // macOS answers its own accelerators from a real menu bar; nothing here
    // may fire a second time on the Command key.
    assert.equal(commandForInput(keyDown('r', { meta: true })), null);
});

test('zoom moves in steps and stops at both ends', () => {
    assert.equal(zoomLevel(0, 'zoom-in'), 0.5);
    assert.equal(zoomLevel(0, 'zoom-out'), -0.5);
    assert.equal(zoomLevel(3, 'zoom-reset'), 0);
    assert.equal(zoomLevel(5, 'zoom-in'), 5);
    assert.equal(zoomLevel(-3, 'zoom-out'), -3);
    assert.equal(zoomLevel(undefined, 'zoom-in'), 0.5);
});

test('the drawn menu bar shows the menus, and only the menus', () => {
    const menu = {
        items: [
            { label: '&File', submenu: {} },
            { label: 'Open in Browser' },
            { label: '', submenu: {} },
            { label: 'Help', submenu: {} },
        ],
    };

    assert.deepEqual(menuBarItems(menu), [
        { index: 0, label: 'File' },
        { index: 3, label: 'Help' },
    ]);
    assert.deepEqual(menuBarItems(null), []);
});
