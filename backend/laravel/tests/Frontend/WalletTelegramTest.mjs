import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { telegramLaunchParams } from '@/lib/telegram';

/**
 * Whether the wallet believes it is inside Telegram.
 *
 * Everything downstream keys off this one answer: it picks the layout (site
 * chrome or none), it decides that a new recovery phrase is never generated in
 * the frame, and it decides whether a script from telegram.org is fetched at
 * all. Getting it wrong in either direction is visible — a bare wallet on the
 * open web, or a site header inside somebody's chat — so it is pinned here
 * rather than trusted to a manual look.
 */

const WEB_VIEW =
    'https://cyberia.church/wallet#tgWebAppData=user%3D%257B%2522id%2522%253A1%257D&tgWebAppVersion=7.0&tgWebAppPlatform=android&tgWebAppThemeParams=%7B%7D';

test('the launch parameters are read from the fragment Telegram appends', () => {
    const params = telegramLaunchParams(WEB_VIEW);

    assert.equal(params.tgWebAppPlatform, 'android');
    assert.equal(params.tgWebAppVersion, '7.0');
});

test('a Mini App opened without initData is still a Mini App', () => {
    // An inline button in a channel launches the app with no user payload; the
    // platform is the parameter every client sends.
    const params = telegramLaunchParams(
        'https://cyberia.church/wallet#tgWebAppVersion=7.0&tgWebAppPlatform=weba',
    );

    assert.equal(params.tgWebAppPlatform, 'weba');
});

test('the parameters are also accepted from the query string', () => {
    // Some clients keep them there across an internal redirect.
    const params = telegramLaunchParams(
        'https://cyberia.church/wallet?tgWebAppPlatform=ios&tgWebAppVersion=7.0',
    );

    assert.equal(params.tgWebAppPlatform, 'ios');
});

test('an ordinary visit is never mistaken for Telegram', () => {
    for (const url of [
        'https://cyberia.church/wallet',
        'https://cyberia.church/wallet#send',
        'https://cyberia.church/wallet?ref=telegram',
        // A hash that merely mentions the word, and one missing the platform.
        'https://cyberia.church/wallet#tgWebApp',
        'https://cyberia.church/wallet#tgWebAppVersion=7.0',
    ]) {
        assert.equal(telegramLaunchParams(url), null, url);
    }
});

/**
 * The Mini App's furniture is wired with `immediate` watches, so it runs while
 * `setup` is still executing — reading `telegramBack` there evaluates the
 * navigation table and the openers. Declared further down the file, those are
 * still in their temporal dead zone, and the whole page throws before its
 * first paint: the Mini App opened on a black screen while the same wallet was
 * fine in a browser tab, because nothing outside Telegram runs that block.
 *
 * Source order is the fix, so source order is what is pinned.
 */
test('the Telegram wiring sits below the navigation it reads', () => {
    const source = readFileSync(
        new URL('../../resources/js/pages/Wallet.vue', import.meta.url),
        'utf8',
    );

    const wiring = source.indexOf('telegram mini app ---');

    assert.ok(wiring > 0, 'the Telegram section is gone from Wallet.vue');

    for (const declaration of [
        'const PARENTS',
        'const openSection',
        'const openSend',
    ]) {
        const at = source.indexOf(declaration);

        assert.ok(at > 0, `${declaration} is gone from Wallet.vue`);
        assert.ok(
            at < wiring,
            `${declaration} must be initialised before the Telegram watches read it`,
        );
    }
});
