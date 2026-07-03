import { router, usePage } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import { apiFetch } from '@/lib/http';
import {
    index as notificationsIndex,
    read as notificationRead,
    readAll as notificationsReadAll,
} from '@/routes/notifications';
import type {
    AppNotification,
    NotificationsPayload,
} from '@/types/notifications';

const POLL_INTERVAL_MS = 30_000;

// Module-scope singleton state so the bell and any other consumer stay in sync.
const items = ref<AppNotification[]>([]);
const unreadCount = ref(0);
const loaded = ref(false);
let started = false;

async function refresh(): Promise<void> {
    try {
        const payload = await apiFetch<NotificationsPayload>(
            notificationsIndex().url,
        );

        items.value = payload.notifications;
        unreadCount.value = payload.unread;
        loaded.value = true;
    } catch {
        // Network hiccup — keep the last known state, next poll retries.
    }
}

export function useNotifications() {
    const page = usePage();

    const isAuthenticated = computed(() => !!page.props.auth?.user);

    onMounted(() => {
        if (started || !isAuthenticated.value) {
            return;
        }

        started = true;

        // Seed the badge from the Inertia shared prop, then poll.
        const shared = page.props.notifications as
            | { unread?: number }
            | undefined;

        if (typeof shared?.unread === 'number') {
            unreadCount.value = shared.unread;
        }

        void refresh();
        useIntervalFn(() => void refresh(), POLL_INTERVAL_MS);
        router.on('navigate', () => void refresh());
    });

    async function markAllRead(): Promise<void> {
        unreadCount.value = 0;
        items.value = items.value.map((item) => ({
            ...item,
            read_at: item.read_at ?? new Date().toISOString(),
        }));

        try {
            await apiFetch(notificationsReadAll().url, { method: 'POST' });
        } catch {
            void refresh();
        }
    }

    async function markRead(notification: AppNotification): Promise<void> {
        if (notification.read_at) {
            return;
        }

        notification.read_at = new Date().toISOString();
        unreadCount.value = Math.max(0, unreadCount.value - 1);

        try {
            await apiFetch(notificationRead(notification.id).url, {
                method: 'POST',
            });
        } catch {
            void refresh();
        }
    }

    return {
        items,
        unreadCount,
        loaded,
        isAuthenticated,
        refresh,
        markAllRead,
        markRead,
    };
}
