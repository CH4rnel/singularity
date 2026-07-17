<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ChangelogRelease } from '@/types';

defineProps<{
    currentVersion: string;
    releases: ChangelogRelease[];
}>();
</script>

<template>
    <Head title="Changelog" />

    <section class="mx-auto w-full max-w-4xl px-4 py-10 sm:py-14">
        <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="font-mono text-sm text-muted-foreground">
                    {{ currentVersion }}
                </p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight">
                    Changelog
                </h1>
            </div>
            <p class="max-w-xl text-sm leading-6 text-muted-foreground">
                User-visible Cyberia app changes, release notes, and operational
                checkpoints.
            </p>
        </header>

        <div class="space-y-5">
            <article
                v-for="entry in releases"
                :key="entry.version"
                class="rounded-lg border bg-card p-5 shadow-xs"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2 border-b pb-4"
                >
                    <div>
                        <h2 class="font-mono text-lg font-semibold">
                            {{ entry.version }}
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ entry.title }}
                        </p>
                    </div>
                    <time class="font-mono text-sm text-muted-foreground">
                        {{ entry.date }}
                    </time>
                </div>

                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <section
                        v-for="section in entry.sections"
                        :key="`${entry.version}-${section.label}`"
                    >
                        <h3
                            class="text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            {{ section.label }}
                        </h3>
                        <ul class="mt-2 space-y-2 text-sm leading-6">
                            <li
                                v-for="item in section.items"
                                :key="item"
                                class="border-l-2 border-brand-cyan/70 pl-3"
                            >
                                {{ item }}
                            </li>
                        </ul>
                    </section>
                </div>
            </article>
        </div>
    </section>
</template>
