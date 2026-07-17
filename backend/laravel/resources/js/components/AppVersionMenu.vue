<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { AppRelease } from '@/types';

const page = usePage<{ release: AppRelease }>();

const release = computed(() => page.props.release);
</script>

<template>
    <DropdownMenu v-if="release">
        <DropdownMenuTrigger :as-child="true">
            <Button
                variant="outline"
                size="sm"
                class="h-8 gap-1 px-2 font-mono text-xs"
                :title="`Changelog ${release.current.version}`"
            >
                {{ release.current.version }}
                <ChevronDown class="h-3.5 w-3.5 opacity-70" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <DropdownMenuLabel>Changelog</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <div class="max-h-96 overflow-y-auto px-1 py-1">
                <section
                    v-for="entry in release.recent"
                    :key="entry.version"
                    class="rounded-md px-2 py-2"
                >
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="font-mono text-xs font-semibold">
                            {{ entry.version }}
                        </p>
                        <time class="text-xs text-muted-foreground">
                            {{ entry.date }}
                        </time>
                    </div>
                    <p class="mt-1 text-sm font-medium">{{ entry.title }}</p>
                    <div
                        v-for="section in entry.sections"
                        :key="`${entry.version}-${section.label}`"
                        class="mt-2"
                    >
                        <p
                            class="text-[11px] font-semibold tracking-widest text-muted-foreground uppercase"
                        >
                            {{ section.label }}
                        </p>
                        <ul
                            class="mt-1 space-y-1 text-xs text-muted-foreground"
                        >
                            <li
                                v-for="item in section.items"
                                :key="item"
                                class="leading-snug"
                            >
                                {{ item }}
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem :as-child="true">
                <Link :href="release.changelogUrl" class="w-full">
                    Full changelog
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
