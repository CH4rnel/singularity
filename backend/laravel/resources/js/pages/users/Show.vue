<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { Check, Copy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FeedItem from '@/components/dao/FeedItem.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SimplePagination from '@/components/web3/SimplePagination.vue';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import type { Activity } from '@/types';
import type { Paginated } from '@/types/pagination';

type Props = {
    profile: {
        id: number;
        name: string | null;
        wallet_address: string | null;
        solana_wallet_address: string | null;
        created_at: string | null;
    };
    stats: {
        proposals: number;
        votes: number;
        comments: number;
    };
    activities: Paginated<Activity>;
};

const props = defineProps<Props>();

const joinedAgo = useTimeAgo(computed(() => props.profile.created_at ?? ''));

const displayName = computed(
    () =>
        props.profile.name ||
        (props.profile.wallet_address
            ? props.profile.wallet_address.slice(0, 6) +
              '…' +
              props.profile.wallet_address.slice(-4)
            : `User #${props.profile.id}`),
);

const copied = ref(false);

async function copyWallet() {
    if (!props.profile.wallet_address) {
        return;
    }

    await navigator.clipboard.writeText(props.profile.wallet_address);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
}

const statTiles = computed(() => [
    { label: 'Proposals', value: props.stats.proposals },
    { label: 'Votes', value: props.stats.votes },
    { label: 'Comments', value: props.stats.comments },
]);
</script>

<template>
    <Head :title="displayName" />

    <div class="mx-auto max-w-4xl px-4 py-8">
        <!-- Profile hero -->
        <header
            class="mb-6 flex flex-col items-start gap-4 rounded-lg border border-border/70 bg-card p-6 sm:flex-row sm:items-center"
        >
            <WalletAvatar
                :seed="props.profile.wallet_address"
                :name="props.profile.name"
                size="lg"
            />
            <div class="min-w-0 flex-1">
                <h1 class="text-2xl font-extrabold tracking-tight">
                    {{ displayName }}
                </h1>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <template v-if="props.profile.wallet_address">
                        <Badge variant="secondary" class="font-mono text-xs">
                            {{ props.profile.wallet_address }}
                        </Badge>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            @click="copyWallet"
                        >
                            <Check
                                v-if="copied"
                                class="h-3.5 w-3.5 text-brand-cyan"
                            />
                            <Copy v-else class="h-3.5 w-3.5" />
                        </Button>
                    </template>
                    <span
                        v-if="props.profile.created_at"
                        class="text-xs text-muted-foreground"
                    >
                        joined {{ joinedAgo }}
                    </span>
                </div>
            </div>
        </header>

        <!-- Stats -->
        <div class="mb-6 grid grid-cols-3 gap-3">
            <div
                v-for="tile in statTiles"
                :key="tile.label"
                class="rounded-lg border border-border/70 bg-card p-4 text-center"
            >
                <p class="font-mono text-2xl font-bold">{{ tile.value }}</p>
                <p class="text-xs tracking-widest text-muted-foreground uppercase">
                    {{ tile.label }}
                </p>
            </div>
        </div>

        <!-- Activity -->
        <section class="space-y-3">
            <h2 class="text-sm font-semibold tracking-widest text-muted-foreground uppercase">
                Activity
            </h2>
            <FeedItem
                v-for="activity in props.activities.data"
                :key="activity.id"
                :activity="activity"
            />
            <p
                v-if="props.activities.data.length === 0"
                class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                No activity yet.
            </p>
            <SimplePagination :paginator="props.activities" />
        </section>
    </div>
</template>
