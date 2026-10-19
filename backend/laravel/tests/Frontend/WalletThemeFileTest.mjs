import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { parseThemeFile, themeFileCss } from '@/lib/wallet/themeFile';

/**
 * A theme is a CSS file, and only the wallet's own variables are taken from
 * it: a theme can repaint and reshape the wallet, never hide what it says or
 * reach the network.
 */

test('variables on .cw and on the light face are taken', () => {
    const theme = parseThemeFile(`
        /* Theme: Dusk */
        .cw { --cw-accent: #ff7a59; --cw-radius-card: 4px; }
        .cw[data-cw-theme='light'] { --cw-accent: #b33a1c; }
    `);

    assert.equal(theme.name, 'Dusk');
    assert.deepEqual(theme.base, {
        '--cw-accent': '#ff7a59',
        '--cw-radius-card': '4px',
    });
    assert.deepEqual(theme.light, { '--cw-accent': '#b33a1c' });
    assert.deepEqual(theme.skipped, []);
});

test('rules that could hide or rewrite the wallet are skipped, not applied', () => {
    const theme = parseThemeFile(`
        .cw { --cw-accent: red; color: red; --cw-canvas: url(https://evil.example/x); }
        .cw-addr { display: none; }
        .cw-addr::after { content: "0xattacker"; }
        @import url(https://evil.example/a.css);
    `);
    const css = themeFileCss(theme);

    assert.deepEqual(theme.base, { '--cw-accent': 'red' });
    assert.ok(theme.skipped.length >= 4);
    assert.ok(!css.includes('display'));
    assert.ok(!css.includes('content'));
    assert.ok(!css.includes('url('));
    assert.ok(!css.includes('@import'));
});

test('a value cannot break out of its declaration', () => {
    const theme = parseThemeFile(
        '.cw { --cw-accent: red } body { background: red }',
    );
    const css = themeFileCss(theme);

    assert.ok(!css.includes('body'));
    assert.equal((css.match(/{/g) ?? []).length, 1);
});

test('the shipped theme files apply whole', async () => {
    for (const name of ['neon', 'template']) {
        const css = await readFile(`public/wallet-themes/${name}.css`, 'utf8');
        const theme = parseThemeFile(css);

        assert.deepEqual(
            theme.skipped,
            [],
            `${name}.css: ${theme.skipped.join(', ')}`,
        );
        assert.ok(Object.keys(theme.base).length > 20, name);
    }
});
