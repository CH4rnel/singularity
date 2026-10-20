import assert from 'node:assert/strict';
import test from 'node:test';
import {
    isDappHiddenIn,
    isHiddenIn,
    storeEditionOf,
} from '@/lib/storeEdition';

/**
 * What a store build of the mobile app leaves out.
 *
 * The token is the only thing that tells a store build from the APK, so the
 * tests that matter are the ones where it is absent: a browser, the desktop
 * app and the sideloaded APK must see the whole wallet.
 */

const ANDROID_WEBVIEW =
    'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36';

test('only the store token names an edition', () => {
    assert.equal(storeEditionOf(ANDROID_WEBVIEW), null);
    assert.equal(storeEditionOf(`${ANDROID_WEBVIEW} CyberiaMobile/1.0.0`), null);
    assert.equal(storeEditionOf(`${ANDROID_WEBVIEW} CyberiaDesktop/1.4.0`), null);
    assert.equal(
        storeEditionOf(`${ANDROID_WEBVIEW} CyberiaMobile/1.0.0 CyberiaStore/play`),
        'play',
    );
    assert.equal(
        storeEditionOf('iPhone CyberiaMobile/1.0.0 CyberiaStore/appstore'),
        'appstore',
    );
    // An edition this file does not know is not a store build.
    assert.equal(storeEditionOf('CyberiaMobile/1.0.0 CyberiaStore/huawei'), null);
});

test('outside a store build nothing is hidden', () => {
    for (const section of ['launchpad', 'stocks', 'torrent', 'tracker']) {
        assert.equal(isHiddenIn(null, section), false);
    }

    assert.equal(isDappHiddenIn(null, 'predictions'), false);
});

test('both stores leave out the launchpad and tokenised stocks', () => {
    for (const edition of ['play', 'appstore']) {
        assert.equal(isHiddenIn(edition, 'launchpad'), true);
        assert.equal(isHiddenIn(edition, 'stocks'), true);
        // The wallet itself stays whole.
        for (const section of ['portfolio', 'bridge', 'earn', 'nft', 'dao', 'chat', 'feed']) {
            assert.equal(isHiddenIn(edition, section), false, `${edition}:${section}`);
        }
    }
});

test('torrents are left out of the App Store build only', () => {
    assert.equal(isHiddenIn('play', 'torrent'), false);
    assert.equal(isHiddenIn('appstore', 'torrent'), true);
    assert.equal(isHiddenIn('appstore', 'tracker'), true);
    assert.equal(isHiddenIn('appstore', 'trackerPublish'), true);
});

test('prediction markets are never offered from The Wired in a store build', () => {
    assert.equal(isDappHiddenIn('play', 'predictions'), true);
    assert.equal(isDappHiddenIn('appstore', 'predictions'), true);
    // Rows that open inside the wallet stay.
    assert.equal(isDappHiddenIn('play', 'swap'), false);
    assert.equal(isDappHiddenIn('appstore', 'dao'), false);
});
