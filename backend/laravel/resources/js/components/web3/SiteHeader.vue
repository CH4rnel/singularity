<script setup lang="ts">
import { Link as InertiaLink, usePage } from '@inertiajs/vue3';
import { ExternalLink, Menu } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AppVersionMenu from '@/components/AppVersionMenu.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import { Button } from '@/components/ui/button';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuList,
    NavigationMenuTrigger,
} from '@/components/ui/navigation-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { navGroups } from '@/components/web3/nav';
import WalletMenu from '@/components/web3/WalletMenu.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const { isCurrentOrParentUrl } = useCurrentUrl();

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const mobileOpen = ref(false);

function isGroupActive(items: { href: string; external?: boolean }[]): boolean {
    return items.some(
        (item) => !item.external && isCurrentOrParentUrl(item.href),
    );
}
</script>

<template>
    <header
        class="sticky top-0 z-40 border-b border-border/70 bg-background/90 backdrop-blur"
    >
        <nav
            class="mx-auto flex h-14 max-w-7xl items-center gap-2 px-4"
            aria-label="Cyberia navigation"
        >
            <!-- Mobile menu -->
            <div class="lg:hidden">
                <Sheet v-model:open="mobileOpen">
                    <SheetTrigger as-child>
                        <Button variant="ghost" size="icon" class="h-9 w-9">
                            <Menu class="h-5 w-5" />
                            <span class="sr-only">Open navigation</span>
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        class="w-[300px] overflow-y-auto p-6"
                    >
                        <SheetTitle class="sr-only">Navigation menu</SheetTitle>
                        <SheetHeader class="flex justify-start text-left">
                            <a
                                href="/"
                                class="text-sm font-extrabold tracking-[0.25em] uppercase"
                            >
                                Cyberia<span class="text-brand-cyan">_</span>
                            </a>
                        </SheetHeader>
                        <div class="space-y-6 py-6">
                            <div
                                v-for="group in navGroups"
                                :key="group.label"
                                class="space-y-1"
                            >
                                <p
                                    class="px-3 pb-1 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
                                >
                                    {{ group.label }}
                                </p>
                                <template
                                    v-for="item in group.items"
                                    :key="item.title"
                                >
                                    <a
                                        v-if="item.external"
                                        :href="item.href"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                                    >
                                        {{ item.title }}
                                        <ExternalLink
                                            class="h-3 w-3 opacity-60"
                                        />
                                    </a>
                                    <InertiaLink
                                        v-else
                                        :href="item.href"
                                        class="flex items-center rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
                                        :class="
                                            isCurrentOrParentUrl(item.href)
                                                ? 'bg-accent text-accent-foreground'
                                                : ''
                                        "
                                        @click="mobileOpen = false"
                                    >
                                        {{ item.title }}
                                    </InertiaLink>
                                </template>
                            </div>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>

            <!-- Logo -->
            <a
                href="/"
                class="mr-2 shrink-0 text-sm font-extrabold tracking-[0.25em] uppercase"
            >
                Cyberia<span class="text-brand-cyan">_</span>
            </a>

            <!-- Desktop menu -->
            <div class="hidden h-full flex-1 lg:flex">
                <NavigationMenu class="flex h-full items-stretch">
                    <NavigationMenuList
                        class="flex h-full items-stretch space-x-1"
                    >
                        <NavigationMenuItem
                            v-for="group in navGroups"
                            :key="group.label"
                            class="relative flex h-full items-center"
                        >
                            <NavigationMenuTrigger
                                class="h-9 bg-transparent px-3"
                                :class="
                                    isGroupActive(group.items)
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ group.label }}
                            </NavigationMenuTrigger>
                            <div
                                v-if="isGroupActive(group.items)"
                                class="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-brand-cyan"
                            ></div>
                            <NavigationMenuContent>
                                <ul class="grid w-56 gap-1 p-2">
                                    <li
                                        v-for="item in group.items"
                                        :key="item.title"
                                    >
                                        <a
                                            v-if="item.external"
                                            :href="item.href"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
                                        >
                                            {{ item.title }}
                                            <ExternalLink
                                                class="h-3 w-3 opacity-60"
                                            />
                                        </a>
                                        <InertiaLink
                                            v-else
                                            :href="item.href"
                                            class="flex items-center rounded-md px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
                                            :class="
                                                isCurrentOrParentUrl(item.href)
                                                    ? 'bg-accent font-medium text-accent-foreground'
                                                    : ''
                                            "
                                        >
                                            {{ item.title }}
                                        </InertiaLink>
                                    </li>
                                </ul>
                            </NavigationMenuContent>
                        </NavigationMenuItem>
                    </NavigationMenuList>
                </NavigationMenu>
            </div>

            <div
                class="flex flex-1 items-center justify-end gap-2 lg:flex-none"
            >
                <slot name="right" />
                <AppVersionMenu />
                <NotificationBell v-if="isAuthenticated" />
                <WalletMenu />
            </div>
        </nav>
    </header>
</template>
