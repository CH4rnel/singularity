<script setup lang="ts">
/**
 * The frame the wallet gets, in a browser tab exactly as in the apps.
 *
 * It began as the shells' chrome — a native app that opens on a web navbar
 * reads as a browser somebody forgot to close — and the site header stayed on
 * `/wallet` in a browser for a while longer. That was the wrong half of the
 * argument. The wallet is an application wherever it is opened: it owns the
 * viewport, it has its own navigation along the bottom and its own bar along
 * the top, and above all of that sat a second navigation, a second brand and a
 * second account menu belonging to something else. On a phone that stack cost
 * about a fifth of the screen before the first balance.
 *
 * So there is no site chrome here in any container. What is left is background,
 * safe-area insets and full height, with the page painting everything inside.
 * The rest of Cyberia is not amputated — the wallet links out to it — and every
 * other route still renders with the normal site layout.
 *
 * The frame is `h-dvh`, not `min-h-screen`, and `main` may shrink below its
 * content: an app window does not scroll as a page. A minimum only says "at
 * least this tall", which leaves the height indefinite, so the wallet's own
 * scrolling panes (the rail, the screen body) grow the document instead of
 * scrolling inside it — a maximized window ended up a few dozen pixels taller
 * than the screen.
 *
 * An app window also does not *zoom*, and that is the other half of the same
 * complaint. Clipping the document stopped it scrolling and the app still moved
 * under the finger, because a zoomed page does not scroll — it pans, in the
 * visual viewport, where no `overflow` rule can reach it. A pinch nobody meant
 * to make, or an input smaller than 16px taking focus on iOS, and from then on
 * the whole thing drags: background, frame and tab bar together, which is
 * exactly what a scroll looks like. So while this frame is on screen the page
 * is pinned at scale 1.
 *
 * Only in a window that is an application — an installed PWA, a shell, a Mini
 * App. In an ordinary browser tab the page stays zoomable, because a wallet
 * draws 11px labels and taking magnification away from somebody who needs it is
 * not a bug fix. The site's own meta is restored on the way out, so the rest of
 * Cyberia is untouched by this.
 */
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { useWalletTheme } from '@/composables/useWalletTheme';
import { isNativeShell } from '@/lib/native';

const { scheme } = useWalletTheme();

const APP_VIEWPORT =
    'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover';

/**
 * Is this window an application rather than a tab?
 *
 * The shells say so themselves. An installed PWA does not — `native.ts` is
 * deliberate that it is not a shell, since it runs the site's own code with no
 * bridge of any kind — so it is recognised by `display-mode`, plus the older
 * `navigator.standalone` that iOS home-screen apps still answer instead.
 */
const isAppWindow = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    const displayed = ['standalone', 'fullscreen', 'minimal-ui'].some(
        (mode) => window.matchMedia(`(display-mode: ${mode})`).matches,
    );

    return (
        isNativeShell() ||
        displayed ||
        (window.navigator as { standalone?: boolean }).standalone === true
    );
};

const metaTag = (name: string): HTMLMetaElement | null =>
    document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`);

let siteViewport: string | null = null;
let siteThemeColour: string | null = null;

/**
 * The bands the system paints around an app window, and they do not all come
 * from the same place.
 *
 * `theme-color` is the one that is documented and the one everybody reaches for
 * first: it colours the status bar at the top and the address bar in a tab, and
 * it is what took the black strip off the top of the installed app. The *bottom*
 * band — the area Android keeps for the gesture handle or the three buttons —
 * ignores it. That one is tinted from the page's own ground, so the document
 * has to actually be the colour the wallet is painted in, rather than a dark
 * site background hidden under a frame that covers it.
 *
 * `color-scheme` is sent with them, because a browser decides several things it
 * draws itself — scrollbars, form controls, the tint it picks when it has to
 * guess — from what the page says it is, and the wallet's scheme is not the
 * site's `dark` class.
 *
 * All three are read off the frame's own `--cw-app` and its live scheme rather
 * than written down again here: the palette lives in one file, and a second
 * copy of a hex value is a copy that goes stale the first time somebody adjusts
 * the ground.
 */
const paintWindowChrome = (): void => {
    const frame = document.querySelector('.cw-frame');

    if (!frame) {
        return;
    }

    const ground = getComputedStyle(frame).getPropertyValue('--cw-app').trim();

    if (!ground) {
        return;
    }

    const meta = metaTag('theme-color');

    if (meta) {
        if (siteThemeColour === null) {
            siteThemeColour = meta.content;
        }

        meta.content = ground;
    }

    document.documentElement.style.backgroundColor = ground;
    document.documentElement.style.colorScheme = scheme.value;
    document.body.style.backgroundColor = ground;
};

onMounted(() => {
    paintWindowChrome();

    const meta = metaTag('viewport');

    if (!meta || !isAppWindow()) {
        return;
    }

    siteViewport = meta.content;
    meta.content = APP_VIEWPORT;
    document.documentElement.dataset.appWindow = 'true';
});

watch(scheme, () => paintWindowChrome());

onBeforeUnmount(() => {
    const viewport = metaTag('viewport');
    const themeColour = metaTag('theme-color');

    if (viewport && siteViewport !== null) {
        viewport.content = siteViewport;
    }

    if (themeColour && siteThemeColour !== null) {
        themeColour.content = siteThemeColour;
    }

    document.documentElement.style.removeProperty('background-color');
    document.documentElement.style.removeProperty('color-scheme');
    document.body.style.removeProperty('background-color');

    siteViewport = null;
    siteThemeColour = null;
    delete document.documentElement.dataset.appWindow;
});
</script>

<template>
    <div
        class="cw-window flex h-dvh flex-col bg-background pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-foreground"
    >
        <main class="flex min-h-0 flex-1 flex-col">
            <slot />
        </main>
    </div>
</template>
