/**
 * The wallet inside Telegram.
 *
 * A Mini App is this same site rendered in Telegram's web view, so there is no
 * second wallet to build and no second place for a key to live: `/wallet` runs
 * exactly as it does in a browser tab, minus the site chrome, plus the two
 * pieces of furniture Telegram owns — the back button in its header and the
 * main button along the bottom.
 *
 * **Telegram is told nothing.** The SDK is loaded only when the page is
 * actually running inside Telegram, `initData` is never forwarded anywhere, and
 * nothing touches `CloudStorage`: a vault synced through Telegram's servers
 * would be a vault Telegram holds, which is the opposite of the promise this
 * wallet makes. The keys stay in this device's storage, as everywhere else.
 *
 * @see https://core.telegram.org/bots/webapps
 */

/** The slice of the SDK this app uses. Telegram's own types are not shipped. */
interface TelegramMainButton {
    text: string;
    isVisible: boolean;
    show(): void;
    hide(): void;
    enable(): void;
    disable(): void;
    setParams(params: {
        text?: string;
        color?: string;
        text_color?: string;
        is_active?: boolean;
        is_visible?: boolean;
    }): void;
    onClick(handler: () => void): void;
    offClick(handler: () => void): void;
}

interface TelegramBackButton {
    show(): void;
    hide(): void;
    onClick(handler: () => void): void;
    offClick(handler: () => void): void;
}

export interface TelegramWebApp {
    initData: string;
    /**
     * Only ever read for `start_param`, the campaign payload a Mini App was
     * opened with. Nothing else in here is touched: `initData` identifies a
     * Telegram account, and this wallet does not want to know one.
     */
    initDataUnsafe?: { start_param?: string };
    version: string;
    platform: string;
    colorScheme: 'light' | 'dark';
    viewportStableHeight?: number;
    isExpanded: boolean;
    ready(): void;
    expand(): void;
    close(): void;
    openLink(url: string, options?: { try_instant_view?: boolean }): void;
    setHeaderColor(color: string): void;
    setBackgroundColor(color: string): void;
    disableVerticalSwipes?(): void;
    MainButton: TelegramMainButton;
    BackButton: TelegramBackButton;
    HapticFeedback?: {
        impactOccurred(style: 'light' | 'medium' | 'heavy'): void;
        notificationOccurred(type: 'error' | 'success' | 'warning'): void;
    };
}

declare global {
    interface Window {
        Telegram?: { WebApp?: TelegramWebApp };
    }
}

const SDK_URL = 'https://telegram.org/js/telegram-web-app.js?57';

/** The wallet's own background, so Telegram's chrome does not fight it. */
const FRAME_COLOR = '#07080a';

/**
 * Telegram appends its launch parameters to the URL fragment. Reading them is
 * how the page knows where it is *before* any script has loaded — the layout
 * has to be decided synchronously, and a wallet that flashes a site header
 * inside a chat looks like the wrong page opened.
 *
 * Pure, and pinned by tests: everything downstream keys off this answer.
 */
export function telegramLaunchParams(
    url: string,
): Record<string, string> | null {
    const fragment = url.includes('#') ? url.slice(url.indexOf('#') + 1) : '';
    const query = url.includes('?')
        ? url.slice(url.indexOf('?') + 1).split('#')[0]
        : '';

    for (const source of [fragment, query]) {
        if (!source.includes('tgWebApp')) {
            continue;
        }

        const params = Object.fromEntries(new URLSearchParams(source));

        // The platform is the one parameter Telegram always sends, on every
        // client. `tgWebAppData` is absent when a Mini App is opened from an
        // inline button in a channel, so keying off it would miss those.
        if (params.tgWebAppPlatform) {
            return params;
        }
    }

    return null;
}

let launch: Record<string, string> | null = null;
let resolved = false;

function launchParams(): Record<string, string> | null {
    if (!resolved) {
        launch =
            typeof window === 'undefined'
                ? null
                : telegramLaunchParams(window.location.href);
        resolved = true;
    }

    return launch;
}

/** Whether this page is running inside Telegram's web view. */
export function isTelegramMiniApp(): boolean {
    return (
        launchParams() !== null ||
        (typeof window !== 'undefined' &&
            typeof window.Telegram?.WebApp?.initData === 'string' &&
            window.Telegram.WebApp.platform !== 'unknown')
    );
}

/**
 * The `startapp` payload a Mini App was opened with, or null.
 *
 * `t.me/<bot>/app?startapp=<value>` arrives here as `tgWebAppStartParam`, and
 * inside a chat it is the only campaign channel there is — there is no URL bar
 * to carry a `utm_source` and no referrer to read. Analytics treats it as the
 * campaign name; see `lib/analytics/attribution.ts`.
 */
export function telegramStartParam(): string | null {
    const value =
        launchParams()?.tgWebAppStartParam ??
        window.Telegram?.WebApp?.initDataUnsafe?.start_param ??
        null;

    return typeof value === 'string' && value !== '' ? value.slice(0, 100) : null;
}

/** Which Telegram client, for the few places it changes what is possible. */
export function telegramPlatform(): string | null {
    return (
        launchParams()?.tgWebAppPlatform ??
        window.Telegram?.WebApp?.platform ??
        null
    );
}

export function telegramWebApp(): TelegramWebApp | null {
    return window.Telegram?.WebApp ?? null;
}

let loading: Promise<TelegramWebApp | null> | null = null;

/**
 * Load Telegram's SDK and settle the frame: tell it we are ready, take the
 * whole height, and paint its chrome in the wallet's own colours.
 *
 * The script is fetched from telegram.org, which is why it is fetched *only*
 * here — a site that pulls a Telegram script into every page would tell
 * Telegram about every visitor who never opened a chat.
 */
export function initializeTelegram(): Promise<TelegramWebApp | null> {
    if (!isTelegramMiniApp()) {
        return Promise.resolve(null);
    }

    if (loading) {
        return loading;
    }

    loading = new Promise<TelegramWebApp | null>((resolve) => {
        if (window.Telegram?.WebApp) {
            resolve(window.Telegram.WebApp);

            return;
        }

        const script = document.createElement('script');
        script.src = SDK_URL;
        script.async = true;
        script.onload = () => resolve(window.Telegram?.WebApp ?? null);
        // A Mini App whose SDK failed to load is still a working wallet; it
        // just has no main button, so it must not sit on a blank screen.
        script.onerror = () => resolve(null);
        document.head.append(script);
    }).then((app) => {
        if (!app) {
            return null;
        }

        app.ready();
        app.expand();
        app.setHeaderColor(FRAME_COLOR);
        app.setBackgroundColor(FRAME_COLOR);
        // A downward swipe closing the app mid-transaction is a lost signature;
        // the wallet scrolls its own panes.
        app.disableVerticalSwipes?.();
        document.documentElement.dataset.telegram = app.platform;

        return app;
    });

    return loading;
}

/**
 * Put the screen's primary action on Telegram's main button.
 *
 * Deliberately **not** used for signing. The main button is a tap, and every
 * signature in this wallet is a hold — a gesture that cannot be performed by
 * accident and cannot be performed by a page. Mirroring "hold to sign" onto a
 * tap would quietly remove that, so the signing screens leave the button
 * hidden and keep the hold control in the page.
 */
export function setMainButton(options: {
    text: string;
    onClick: () => void;
    enabled?: boolean;
}): () => void {
    const app = telegramWebApp();

    if (!app) {
        return () => {};
    }

    const enabled = options.enabled ?? true;

    app.MainButton.setParams({
        text: options.text,
        color: '#2fe9e0',
        text_color: '#04191a',
        is_active: enabled,
        is_visible: true,
    });
    app.MainButton.onClick(options.onClick);

    return () => {
        app.MainButton.offClick(options.onClick);
        app.MainButton.hide();
    };
}

export function hideMainButton(): void {
    telegramWebApp()?.MainButton.hide();
}

/**
 * Telegram's own back arrow, wired to the wallet's navigation.
 *
 * Without this the header arrow closes the whole Mini App, which from inside a
 * send screen reads as the app crashing.
 */
export function setBackButton(onClick: (() => void) | null): () => void {
    const app = telegramWebApp();

    if (!app) {
        return () => {};
    }

    if (!onClick) {
        app.BackButton.hide();

        return () => {};
    }

    app.BackButton.onClick(onClick);
    app.BackButton.show();

    return () => {
        app.BackButton.offClick(onClick);
        app.BackButton.hide();
    };
}

/** A short buzz on the actions worth feeling. No-op outside Telegram. */
export function telegramHaptic(
    type: 'light' | 'medium' | 'heavy' = 'light',
): void {
    telegramWebApp()?.HapticFeedback?.impactOccurred(type);
}

/**
 * Open a link outside the Mini App.
 *
 * An external site opened inside Telegram's frame has no address bar, which is
 * exactly the wrong place to send someone who is about to check an explorer.
 */
export function telegramOpenLink(url: string): void {
    const app = telegramWebApp();

    if (app) {
        app.openLink(url);
    } else {
        window.open(url, '_blank', 'noopener');
    }
}
