<script setup lang="ts">
/**
 * The chrome the wallet gets inside the desktop and mobile shells.
 *
 * Those apps exist to be the wallet, so the site header and footer are gone:
 * a native app that opens on a web navbar reads as a browser someone forgot to
 * close. What is left is the frame the app owns — background, safe-area insets
 * and full height — with the page painting everything inside it.
 *
 * The rest of Cyberia is not amputated: the wallet's own masthead links to it,
 * and every other route in the shell still renders with the normal site
 * layout.
 *
 * The frame is `h-dvh`, not `min-h-screen`, and `main` may shrink below its
 * content: an app window does not scroll as a page. A minimum only says "at
 * least this tall", which leaves the height indefinite, so the wallet's own
 * scrolling panes (the rail, the screen body) grow the document instead of
 * scrolling inside it — a maximized window ended up a few dozen pixels taller
 * than the screen.
 */
</script>

<template>
    <div
        class="flex h-dvh flex-col bg-background pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-foreground"
    >
        <main class="flex min-h-0 flex-1 flex-col">
            <slot />
        </main>
    </div>
</template>
