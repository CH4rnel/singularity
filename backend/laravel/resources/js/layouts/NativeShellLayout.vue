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
 */
</script>

<template>
    <div
        class="flex min-h-screen flex-col bg-background pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)] text-foreground"
    >
        <main class="flex flex-1 flex-col">
            <slot />
        </main>
    </div>
</template>
