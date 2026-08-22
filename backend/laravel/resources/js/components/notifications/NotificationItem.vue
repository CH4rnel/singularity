<script setup lang="ts">
import { Link as InertiaLink } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { computed } from 'vue';
import type { AppNotification } from '@/types/notifications';

const props = defineProps<{
    notification: AppNotification;
}>();

const emit = defineEmits<{
    open: [notification: AppNotification];
}>();

const timeAgo = useTimeAgo(computed(() => props.notification.created_at));

/*
 * Where this notice points, and whether Inertia can take us there.
 *
 * Every notification so far has been about something inside the app, so an
 * InertiaLink was the whole story. It stops being true the moment one points
 * at a Telegram handle or an X profile: Inertia would try to fetch an
 * off-origin URL as a page visit and the click would do nothing at all — a
 * dead link is worse than no link, because the reader thinks they followed it.
 */
const external = computed(() => /^https?:\/\//i.test(props.notification.data.url));
</script>

<template>
    <component
        :is="external ? 'a' : InertiaLink"
        v-bind="
            external
                ? { href: notification.data.url, target: '_blank', rel: 'noopener noreferrer' }
                : { href: notification.data.url }
        "
        class="flex items-start gap-3 rounded-md px-3 py-2 text-sm hover:bg-accent hover:text-accent-foreground"
        @click="emit('open', notification)"
    >
        <span
            class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
            :class="
                notification.read_at ? 'bg-transparent' : 'bg-brand-cyan glow-cyan'
            "
        ></span>
        <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">
                {{ notification.data.title }}
            </span>
            <!-- Not truncated: a notice whose whole point is a sentence the
                 reader has to actually read cannot be cut off at the width of
                 a dropdown. -->
            <span class="block text-xs text-muted-foreground">
                {{ notification.data.body }}
            </span>
            <span class="block text-xs text-muted-foreground/70">
                {{ timeAgo }}
            </span>
        </span>
    </component>
</template>