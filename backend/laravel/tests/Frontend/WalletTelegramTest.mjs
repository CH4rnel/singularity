import assert from 'node:assert/strict';
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
