<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { toggle as reactionToggle } from '@/routes/reactions';
import type { Reaction, User } from '@/types';

const PALETTE = ['👍', '🔥', '❤️', '👀', '🚀'];

const props = withDefaults(
    defineProps<{
        reactableType: 'proposal' | 'comment';
        reactableId: number;
        reactions: Reaction[] | undefined;
        compact?: boolean;
    }>(),
    { compact: false },
);

const page = usePage();
const authUser = computed(() => page.props.auth?.user as User | undefined);

const counts = computed(() => {
    const map = new Map<string, { count: number; mine: boolean }>();

    for (const reaction of props.reactions ?? []) {
        const entry = map.get(reaction.emoji) ?? { count: 0, mine: false };
        entry.count += 1;

        if (authUser.value && reaction.user_id === authUser.value.id) {
            entry.mine = true;
        }

        map.set(reaction.emoji, entry);
    }

    return map;
});

// Compact mode (comments) shows only used emojis + one "add" affordance via
// the full palette on hover; full mode (proposal) always shows the palette.
const visiblePalette = computed(() =>
    props.compact
        ? PALETTE.filter((emoji) => (counts.value.get(emoji)?.count ?? 0) > 0)
        : PALETTE,
);

function toggle(emoji: string) {
    if (!authUser.value) {
        return;
    }

    router.post(
        reactionToggle().url,
        {
            reactable_type: props.reactableType,
            reactable_id: props.reactableId,
            emoji,
        },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="group/reactions flex flex-wrap items-center gap-1">
        <button
            v-for="emoji in visiblePalette"
            :key="emoji"
            type="button"
            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors"
            :class="
                counts.get(emoji)?.mine
                    ? 'border-brand-cyan/60 bg-accent text-accent-foreground'
                    : 'border-border/70 text-muted-foreground hover:border-brand-cyan/40 hover:text-foreground'
            "
            :disabled="!authUser"
            @click="toggle(emoji)"
        >
            <span>{{ emoji }}</span>
            <span v-if="(counts.get(emoji)?.count ?? 0) > 0" class="font-mono">
                {{ counts.get(emoji)!.count }}
            </span>
        </button>

        <!-- Compact mode: reveal the full palette on hover so empty comments
             can still be reacted to without permanent clutter. -->
        <span
            v-if="compact && authUser"
            class="hidden gap-1 group-hover/reactions:inline-flex"
        >
            <button
                v-for="emoji in PALETTE.filter(
                    (item) => !visiblePalette.includes(item),
                )"
                :key="emoji"
                type="button"
                class="inline-flex items-center rounded-full border border-dashed border-border/70 px-2 py-0.5 text-xs opacity-60 hover:opacity-100"
                @click="toggle(emoji)"
            >
                {{ emoji }}
            </button>
        </span>
    </div>
</template>
