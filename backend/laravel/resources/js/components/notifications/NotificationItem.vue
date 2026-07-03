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
</script>

<template>
    <InertiaLink
        :href="notification.data.url"
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
            <span class="block truncate text-xs text-muted-foreground">
                {{ notification.data.body }}
            </span>
            <span class="block text-xs text-muted-foreground/70">
                {{ timeAgo }}
            </span>
        </span>
    </InertiaLink>
</template>
