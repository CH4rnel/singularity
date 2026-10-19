/**
 * A wallet theme is a CSS file.
 *
 * ```css
 * /* Theme: Neon *\/
 * .cw {
 *     --cw-canvas: #05070a;
 *     --cw-accent: #2fe9e0;
 *     --cw-radius-card: 4px;
 * }
 * .cw[data-cw-theme='light'] {
 *     --cw-canvas: #dee3e9;
 * }
 * ```
 *
 * Only the wallet's own custom properties are taken from it — colours, fonts,
 * radii, label case, atmosphere — and only from `.cw` / `:root` (both faces)
 * and `.cw[data-cw-theme='light']` (the light face). Everything else in the
 * file is skipped and listed, never applied: in a wallet, a rule that can hide
 * an element or draw `content` can hide the real receiving address and paint
 * another one over it, and a `url()` can report what is on screen to a
 * stranger. A theme from anybody is therefore safe to try, because all it can
 * do is change the values the wallet already uses.
 */

export type ThemeFile = {
    name: string;
    /** Declarations for both faces, then the light face's overrides. */
    base: Record<string, string>;
    light: Record<string, string>;
    /** What was in the file and was not applied, for the note under it. */
    skipped: string[];
};

/** A file is a theme, not a stylesheet for the page: the cap keeps it one. */
export const THEME_FILE_LIMIT = 20_000;

const PROPERTY = /^--cw-[a-z0-9-]{1,48}$/;

/**
 * Values may be colours, numbers with units, keywords and font lists. Nothing
 * that fetches, nests or escapes: no `url(`, `image-set(`, `@`, braces,
 * semicolons, backslashes or angle brackets.
 */
const UNSAFE_VALUE = /url\s*\(|image-set|expression|@|[{};\\<>]/i;

const BASE_SELECTORS = new Set(['.cw', ':root']);

const LIGHT_SELECTOR = /^\.cw\[data-cw-theme=(?:'light'|"light"|light)\]$/;

const stripComments = (css: string): string =>
    css.replace(/\/\*[\s\S]*?\*\//g, ' ');

/** The name from a leading `/* Theme: … *\/` comment, else the fallback. */
const nameOf = (css: string, fallback: string): string => {
    const match = css.match(/\/\*\s*theme\s*:\s*([^*\n]{1,40})/i);

    return match ? match[1].trim() : fallback;
};

export const parseThemeFile = (
    css: string,
    fallbackName = 'My theme',
): ThemeFile => {
    const theme: ThemeFile = {
        name: nameOf(css, fallbackName),
        base: {},
        light: {},
        skipped: [],
    };

    if (css.length > THEME_FILE_LIMIT) {
        theme.skipped.push(`file longer than ${THEME_FILE_LIMIT} characters`);

        return theme;
    }

    const body = stripComments(css);
    const rule = /([^{}]+)\{([^{}]*)\}/g;
    let consumed = 0;
    let match: RegExpExecArray | null;

    while ((match = rule.exec(body)) !== null) {
        const outside = body.slice(consumed, match.index).trim();

        if (outside !== '') {
            theme.skipped.push(outside.slice(0, 60));
        }

        consumed = rule.lastIndex;

        const selector = match[1].trim().replace(/\s+/g, ' ');
        const target = BASE_SELECTORS.has(selector)
            ? theme.base
            : LIGHT_SELECTOR.test(selector)
              ? theme.light
              : null;

        if (target === null) {
            theme.skipped.push(`${selector} { … }`);

            continue;
        }

        for (const declaration of match[2].split(';')) {
            const text = declaration.trim();

            if (text === '') {
                continue;
            }

            const colon = text.indexOf(':');
            const property = text.slice(0, colon).trim().toLowerCase();
            const value = text.slice(colon + 1).trim();

            if (
                colon < 1 ||
                !PROPERTY.test(property) ||
                value === '' ||
                value.length > 200 ||
                UNSAFE_VALUE.test(value)
            ) {
                theme.skipped.push(text.slice(0, 60));

                continue;
            }

            target[property] = value;
        }
    }

    const rest = body.slice(consumed).trim();

    if (rest !== '') {
        theme.skipped.push(rest.slice(0, 60));
    }

    return theme;
};

/** The palette id a theme file is applied under (`data-cw-palette`). */
export const FILE_PALETTE = 'file';

const block = (selector: string, values: Record<string, string>): string =>
    Object.keys(values).length === 0
        ? ''
        : `${selector} {\n${Object.entries(values)
              .map(([property, value]) => `    ${property}: ${value};`)
              .join('\n')}\n}`;

/**
 * The stylesheet a parsed theme becomes. Rebuilt from the accepted
 * declarations rather than copied from the file, so nothing skipped can come
 * back in. The tripled class outranks the light theme's own block whichever
 * order the stylesheets load in.
 */
export const themeFileCss = (theme: ThemeFile | null): string => {
    if (!theme) {
        return '';
    }

    const root = `.cw.cw.cw[data-cw-palette='${FILE_PALETTE}']`;

    return [
        block(root, theme.base),
        block(`${root}[data-cw-theme='light']`, theme.light),
    ]
        .filter((text) => text !== '')
        .join('\n\n');
};

/* --------------------------------------------------------------- storage -- */

const STORAGE_KEY = 'cyberia.wallet.themeFile';

export type StoredThemeFile = {
    /** Which built-in file it came from, or null for one the person chose. */
    source: string | null;
    css: string;
    name: string;
};

export const readThemeFile = (): StoredThemeFile | null => {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? (JSON.parse(raw) as StoredThemeFile) : null;

        return parsed && typeof parsed.css === 'string' ? parsed : null;
    } catch {
        return null;
    }
};

export const writeThemeFile = (theme: StoredThemeFile | null): void => {
    try {
        if (theme === null) {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(theme));
        }
    } catch {
        // Applies to this tab regardless.
    }
};

/** Themes shipped as files in `public/wallet-themes/`. */
export const BUILT_IN_THEME_FILES: readonly { id: string; name: string }[] = [
    { id: 'neon', name: 'Neon' },
];

/** The starting point for writing one: every variable, with its default. */
export const THEME_TEMPLATE_URL = '/wallet-themes/template.css';

export const builtInThemeUrl = (id: string): string =>
    `/wallet-themes/${id}.css`;
