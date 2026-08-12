/**
 * One manifest, said in two dialects.
 *
 * `manifest.json` is the Chromium one because that is the engine with the
 * stricter parser; this is the only place that knows how Gecko differs, and it
 * is a module rather than a branch inside `build.mjs` so the difference can be
 * asserted in tests instead of eyeballed in a build log.
 */

/** Gecko needs a stable id: it keys storage off it and AMO signs against it. */
export const GECKO_ID = 'wallet@cyberia.church';

/**
 * `world: "MAIN"` for a *registered* content script landed in Firefox 128, and
 * without it the provider cannot reach the page at all — so an older Firefox is
 * refused at install rather than left with a wallet that silently never
 * connects to anything.
 */
export const GECKO_MIN = '128.0';

export const TARGETS = {
    chrome: { dir: 'dist', zip: 'Cyberia-extension.zip', engine: 'chrome111' },
    firefox: { dir: 'dist-firefox', zip: 'Cyberia-extension-firefox.zip', engine: 'firefox128' },
};

/**
 * The manifest for one target, at one version.
 *
 * Both background keys cannot live in one file: Chromium rejects
 * `background.scripts` under MV3, and Gecko has no service worker to point at.
 */
export const manifestFor = (base, target, version) => {
    const manifest = structuredClone(base);
    manifest.version = version;

    if (target !== 'firefox') {
        return manifest;
    }

    delete manifest.minimum_chrome_version;
    manifest.background = { scripts: ['background.js'], type: 'module' };
    manifest.browser_specific_settings = {
        gecko: { id: GECKO_ID, strict_min_version: GECKO_MIN },
    };

    return manifest;
};
