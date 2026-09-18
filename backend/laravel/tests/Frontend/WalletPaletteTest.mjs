import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

/**
 * The two wallet palettes, measured rather than described.
 *
 * Contrast is the one thing in a stylesheet that can be wrong without looking
 * wrong to whoever wrote it — a value picked on a calibrated monitor in a dim
 * room passes every review and then fails outdoors on a phone, which is how
 * both of these ramps were last reported. So the ratios are arithmetic here
 * and the comments in `wallet.css` quote what this file enforces.
 *
 * Two rules, and the second is the one that is easy to lose.
 *
 * **Text is measured against every ground it can land on.** A wallet screen
 * paints five of them — the surface, the app behind it, a panel, a well and a
 * sunken row — and a label is written on all five, so the floor applies to the
 * worst pairing and not to the flattering one.
 *
 * **The light theme's grounds stay neutral.** Warm greys read as beige, which
 * is what they were called, and they fight an accent and a network palette
 * that are entirely cool. "Neutral" is checkable: the red and blue channels of
 * a ground may not diverge, and where they do it is towards blue.
 */

const CSS = readFileSync(
    fileURLToPath(new URL('../../resources/css/wallet.css', import.meta.url)),
    'utf8',
);

/** The token block for one theme, as a name → value map. */
const paletteOf = (selector) => {
    const start = CSS.indexOf(selector);

    assert.notEqual(start, -1, `${selector} is missing from wallet.css`);

    const block = CSS.slice(start, CSS.indexOf('\n}', start));
    const tokens = new Map();

    for (const [, name, value] of block.matchAll(
        /(--cw-[a-z0-9-]+):\s*([^;]+);/g,
    )) {
        tokens.set(name, value.trim());
    }

    return tokens;
};

const dark = paletteOf('.cw {');
const light = paletteOf(".cw[data-cw-theme='light'] {");

const rgb = (hex) => {
    assert.match(hex, /^#[0-9a-f]{6}$/i, `${hex} is not a six-digit hex`);

    return [1, 3, 5].map((at) => parseInt(hex.slice(at, at + 2), 16));
};

/** WCAG relative luminance. */
const luminance = (hex) =>
    rgb(hex)
        .map((channel) => {
            const c = channel / 255;

            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        })
        .reduce(
            (total, c, index) => total + [0.2126, 0.7152, 0.0722][index] * c,
            0,
        );

const contrast = (a, b) => {
    const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (high + 0.05) / (low + 0.05);
};

/** Everything a sentence or a label can be written on. */
const GROUNDS = ['--cw-surface', '--cw-app', '--cw-panel', '--cw-well', '--cw-sunken'];

/**
 * The floors, and why the dark theme's is the higher one.
 *
 * Sunlight is additive: a reflection lays a constant amount of light over the
 * whole screen, which barely moves a light theme (its ground is already near
 * that level) and destroys a dark one (its ground is not). The theme that has
 * to survive being carried outdoors is therefore the dark theme, and that is
 * the theme this wallet ships by default.
 *
 * `fainter` is absent on purpose: it is not text and never carries any.
 */
const FLOORS = {
    dark: { quiet: 8.5, loud: 12 },
    light: { quiet: 7, loud: 10 },
};

const QUIET = ['--cw-faint', '--cw-dim', '--cw-muted'];
const LOUD = ['--cw-body', '--cw-text', '--cw-bright'];

for (const [theme, palette] of [
    ['dark', dark],
    ['light', light],
]) {
    test(`${theme}: quiet ink clears its floor on every ground it lands on`, () => {
        for (const [inks, floor] of [
            [QUIET, FLOORS[theme].quiet],
            [LOUD, FLOORS[theme].loud],
        ]) {
            for (const ink of inks) {
                for (const ground of GROUNDS) {
                    const ratio = contrast(palette.get(ink), palette.get(ground));

                    assert.ok(
                        ratio >= floor,
                        `${ink} on ${ground} in the ${theme} theme is ${ratio.toFixed(2)}:1, under ${floor}:1`,
                    );
                }
            }
        }
    });

    test(`${theme}: structure is visible, not merely present`, () => {
        // A hairline is quiet by design; below this it is not drawn at all,
        // which on a screen held outdoors is a card with no edge.
        assert.ok(
            contrast(palette.get('--cw-hairline'), palette.get('--cw-surface')) >= 1.25,
            `the ${theme} hairline has vanished into its card`,
        );
        assert.ok(
            contrast(palette.get('--cw-border'), palette.get('--cw-surface')) >= 1.9,
            `the ${theme} border has vanished into its card`,
        );
    });

    test(`${theme}: a state colour is readable as text, not only as a hue`, () => {
        // These are written as words as often as they are drawn as a dot —
        // "waiting", "failed", an amount in the red — so each one is text on
        // every ground, at WCAG's floor for text that is not quiet.
        for (const state of ['--cw-ok', '--cw-pending', '--cw-bad']) {
            for (const ground of GROUNDS) {
                const ratio = contrast(palette.get(state), palette.get(ground));

                assert.ok(
                    ratio >= 4.5,
                    `${state} on ${ground} in the ${theme} theme is ${ratio.toFixed(2)}:1`,
                );
            }
        }
    });

    test(`${theme}: a primary button's own label is readable on it`, () => {
        assert.ok(
            contrast(palette.get('--cw-accent'), palette.get('--cw-accent-ink')) >= 4.5,
            `the ${theme} accent cannot carry its own ink`,
        );
    });
}

test('the light theme is neutral, never beige', () => {
    for (const ground of [...GROUNDS, '--cw-canvas', '--cw-hairline', '--cw-line']) {
        const [red, , blue] = rgb(light.get(ground));

        assert.ok(
            blue >= red,
            `${ground} is warmer than it is cool (r${red} > b${blue}) — that reads as beige`,
        );
    }
});

test('the dark theme separates its grounds with a line, not with lightness', () => {
    /*
     * A four-rung luminance ladder cannot exist down here — at these levels a
     * step big enough to see is a step out of the theme — which is exactly why
     * the structure is drawn with hairlines and borders, pinned above. What
     * this checks is that the grounds are at least *ordered*, so a card is
     * never darker than the field it sits on, and that the ramp as a whole
     * stays dark enough for the ink floors above to mean anything.
     */
    const ladder = ['--cw-canvas', '--cw-app', '--cw-surface', '--cw-raised'];
    const levels = ladder.map((token) => luminance(dark.get(token)));

    for (let step = 1; step < levels.length; step += 1) {
        assert.ok(
            levels[step] > levels[step - 1],
            `${ladder[step]} is not lighter than ${ladder[step - 1]}`,
        );
    }

    for (const ground of GROUNDS) {
        assert.ok(
            luminance(dark.get(ground)) < 0.03,
            `${ground} has drifted out of the dark end of the ramp`,
        );
    }
});
