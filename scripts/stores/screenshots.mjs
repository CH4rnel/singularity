#!/usr/bin/env node
/**
 * Store screenshots of the mobile app, taken from the live wallet.
 *
 *   npx playwright@1.56.1 install chromium   # once, outside this repo's tooling
 *   node scripts/stores/screenshots.mjs [https://cyberia.church]
 *
 * The app renders the site, so a phone-sized browser carrying the app's user
 * agent *is* the app's screen: same layout (`WalletFrameLayout`), same store
 * edition (`CyberiaStore/…` hides what that store does not take). Each shot is
 * taken in both store sizes and both listing languages and written straight
 * into the folders fastlane uploads from:
 *
 *   frontend/mobile/fastlane/metadata/android/<locale>/images/phoneScreenshots/
 *   frontend/mobile/fastlane/screenshots/ios/<locale>/
 *
 * A first launch makes a fresh, empty wallet — that is what a reviewer sees
 * too, so the shots do not pretend to a balance nobody has.
 */
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = (process.argv[2] ?? 'https://cyberia.church').replace(/\/$/, '');
const root = join(dirname(fileURLToPath(import.meta.url)), '../../frontend/mobile/fastlane');

/** Play: 9:16 phone, 1080×1920. App Store: the 6.9" iPhone class, 1320×2868. */
const DEVICES = [
    {
        store: 'play',
        viewport: { width: 360, height: 640 },
        scale: 3,
        agent: 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Mobile Safari/537.36 CyberiaMobile/1.0.0 CyberiaStore/play',
        dir: (locale) => join(root, 'metadata/android', locale === 'en' ? 'en-US' : 'ru-RU', 'images/phoneScreenshots'),
    },
    {
        store: 'appstore',
        viewport: { width: 440, height: 956 },
        scale: 3,
        agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 CyberiaMobile/1.0.0 CyberiaStore/appstore',
        dir: (locale) => join(root, 'screenshots/ios', locale === 'en' ? 'en-US' : 'ru'),
    },
];

/** What a listing shows, in order: the wallet first, then what it does. */
const SHOTS = [
    { name: '1-portfolio', query: '' },
    { name: '2-earn', query: '?section=earn' },
    { name: '3-nft', query: '?section=nft' },
    { name: '4-feed', query: '?section=feed' },
    { name: '5-chat', query: '?section=chat' },
    { name: '6-dao', query: '?section=dao' },
];

const browser = await chromium.launch();

try {
    for (const device of DEVICES) {
        for (const locale of ['en', 'ru']) {
            const context = await browser.newContext({
                viewport: device.viewport,
                deviceScaleFactor: device.scale,
                isMobile: true,
                hasTouch: true,
                userAgent: device.agent,
                locale,
                colorScheme: 'dark',
            });
            await context.addInitScript((value) => {
                window.localStorage.setItem('locale', value);
            }, locale);

            const page = await context.newPage();
            const dir = device.dir(locale);
            await mkdir(dir, { recursive: true });

            // The first visit makes the vault; every shot after it reuses it.
            await page.goto(`${base}/wallet`, { waitUntil: 'networkidle' });

            for (const shot of SHOTS) {
                await page.goto(`${base}/wallet${shot.query}`, { waitUntil: 'networkidle' });
                await page.waitForTimeout(2500);
                await page.screenshot({ path: join(dir, `${shot.name}.png`) });
                console.log(`${device.store} ${locale} ${shot.name}`);
            }

            await context.close();
        }
    }
} finally {
    await browser.close();
}
