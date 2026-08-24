'use strict';

/**
 * The frameless window's rules, tested under plain Node like the navigation
 * ones — no display, no Electron binary, no window.
 *
 * With no menu bar left to answer Ctrl+R, the key table is the accelerator list.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const {
    commandForInput,
    usesFramelessWindow,
    zoomLevel,
} = require('../src/frame');

const keyDown = (key, modifiers = {}) => ({ type: 'keyDown', key, ...modifiers });

test('the app removes every decoration unless told not to', () => {
    assert.equal(usesFramelessWindow({}, []), true);
    assert.equal(usesFramelessWindow({}, ['--native-frame']), false);
    assert.equal(usesFramelessWindow({ CYBERIA_NATIVE_FRAME: '1' }, []), false);
    assert.equal(usesFramelessWindow({ CYBERIA_NATIVE_FRAME: ' Yes ' }, []), false);
    assert.equal(usesFramelessWindow({ CYBERIA_NATIVE_FRAME: '0' }, []), true);
});

test('the packaged window has one full-size site view and no titlebar view', () => {
    const source = fs.readFileSync(path.join(__dirname, '..', 'src', 'main.js'), 'utf8');

    assert.match(source, /frame: !FRAMELESS/);
    assert.equal(source.match(/new WebContentsView\(/g)?.length, 1);
    assert.doesNotMatch(source, /titlebar\.html|preload-frame|chromeView/);
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
