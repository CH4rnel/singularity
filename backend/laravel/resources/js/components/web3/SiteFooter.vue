<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { download } from '@/routes';

const links = [
    { title: 'Swap', href: 'https://swap.cyberia.church/' },
    { title: 'Explorer', href: 'https://explorer.cyberia.church/' },
    { title: 'Bridge', href: 'https://bridge.cyberia.church/' },
    { title: 'cyberia.church', href: 'https://cyberia.church/' },
];
</script>

<template>
    <footer class="border-t border-border/70">
        <div
            class="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-4 py-6 text-xs text-muted-foreground sm:flex-row"
        >
            <p class="font-extrabold tracking-[0.25em] uppercase">
                Cyberia<span class="text-brand-cyan">_</span>
            </p>
            <div class="flex flex-wrap items-center justify-center gap-4">
                <Link :href="download().url" class="hover:text-foreground">
                    Get the app
                </Link>
                <a
                    v-for="link in links"
                    :key="link.title"
                    :href="link.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-foreground"
                >
                    {{ link.title }}
                </a>
            </div>
        </div>
    </footer>
</template>
