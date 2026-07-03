<script setup lang="ts">
import { Bell, BellOff, BellRing } from 'lucide-vue-next';
import NotificationItem from '@/components/notifications/NotificationItem.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useNotifications } from '@/composables/useNotifications';
import { useWebPush } from '@/composables/useWebPush';

const { items, unreadCount, loaded, markAllRead, markRead } =
    useNotifications();
const webPush = useWebPush();

function togglePush() {
    if (webPush.isSubscribed.value) {
        void webPush.unsubscribe();
    } else {
        void webPush.subscribe();
    }
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="relative h-9 w-9">
                <Bell class="h-5 w-5" />
                <span
                    v-if="unreadCount > 0"
                    class="glow-cyan absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-cyan px-1 font-mono text-[10px] font-bold text-primary-foreground"
                >
                    {{ unreadCount > 99 ? '99+' : unreadCount }}
                </span>
                <span class="sr-only">Notifications</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 p-0 sm:w-96">
            <div class="flex items-center justify-between px-3 py-2">
                <p class="text-sm font-semibold">Notifications</p>
                <Button
                    v-if="unreadCount > 0"
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs text-muted-foreground"
                    @click.prevent="markAllRead"
                >
                    Mark all read
                </Button>
            </div>
            <DropdownMenuSeparator class="my-0" />

            <div class="max-h-96 overflow-y-auto p-1">
                <template v-if="items.length > 0">
                    <NotificationItem
                        v-for="notification in items"
                        :key="notification.id"
                        :notification="notification"
                        @open="markRead"
                    />
                </template>
                <p
                    v-else
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ loaded ? 'No notifications yet' : 'Loading…' }}
                </p>
            </div>

            <template v-if="webPush.isSupported.value">
                <DropdownMenuSeparator class="my-0" />
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2.5 text-left text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground"
                    :disabled="webPush.isBusy.value"
                    @click.prevent="togglePush"
                >
                    <Spinner
                        v-if="webPush.isBusy.value"
                        class="h-3.5 w-3.5"
                    />
                    <BellRing
                        v-else-if="webPush.isSubscribed.value"
                        class="h-3.5 w-3.5 text-brand-cyan"
                    />
                    <BellOff v-else class="h-3.5 w-3.5" />
                    <span>
                        {{
                            webPush.isSubscribed.value
                                ? 'Browser notifications enabled'
                                : 'Enable browser notifications'
                        }}
                    </span>
                </button>
                <p
                    v-if="webPush.error.value"
                    class="px-3 pb-2 text-xs text-destructive"
                >
                    {{ webPush.error.value }}
                </p>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
