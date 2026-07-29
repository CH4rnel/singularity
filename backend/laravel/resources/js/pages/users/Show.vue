<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { Check, Copy, Flame, Trophy } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import FeedItem from '@/components/dao/FeedItem.vue';
import PostCard from '@/components/social/PostCard.vue';
import PostComposer from '@/components/social/PostComposer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import SimplePagination from '@/components/web3/SimplePagination.vue';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { leaderboard } from '@/routes';
import type { Activity } from '@/types';
import type { User } from '@/types/auth';
import type { Paginated } from '@/types/pagination';
import type { PublicProgress } from '@/types/progress';
import type { Post } from '@/types/social';

type Props = {
    profile: {
        id: number;
        name: string | null;
        avatar: string | null;
        wallet_address: string | null;
        solana_wallet_address: string | null;
        created_at: string | null;
    };
    stats: {
        posts: number;
        proposals: number;
        votes: number;
        comments: number;
    };
    posts: Paginated<Post>;
    activities: Paginated<Activity>;
    /** Level, rank and streak — public standing, no quest board. */
    progress: PublicProgress;
};

const props = defineProps<Props>();
const page = usePage();
const authUser = computed(() => page.props.auth?.user as User | undefined);
const isOwnProfile = computed(() => authUser.value?.id === props.profile.id);

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
    { label: 'XP', value: props.progress.xp.toLocaleString() },
    { label: 'Posts', value: props.stats.posts },
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
                :src="props.profile.avatar"
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
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <Badge class="font-mono text-xs">
                        Lv {{ props.progress.level }} ·
                        {{ props.progress.title }}
                    </Badge>
                    <Link
                        v-if="props.progress.rank !== null"
                        :href="leaderboard().url"
                        class="inline-flex items-center gap-1 text-xs text-brand-cyan hover:underline"
                    >
                        <Trophy class="h-3.5 w-3.5" />
                        #{{ props.progress.rank }}
                    </Link>
                    <span
                        v-if="props.progress.current_streak > 1"
                        class="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground"
                    >
                        <Flame class="h-3.5 w-3.5" />
                        {{ props.progress.current_streak }}
                    </span>
                </div>
            </div>
        </header>

        <!-- Stats -->
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div
                v-for="tile in statTiles"
                :key="tile.label"
                class="rounded-lg border border-border/70 bg-card p-4 text-center"
            >
                <p class="font-mono text-2xl font-bold">{{ tile.value }}</p>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ tile.label }}
                </p>
            </div>
        </div>

        <!-- Wall -->
        <section class="mb-8 space-y-3">
            <h2
                class="text-sm font-semibold tracking-widest text-muted-foreground uppercase"
            >
                Wall
            </h2>
            <PostComposer v-if="isOwnProfile" />
            <PostCard
                v-for="post in props.posts.data"
                :key="post.id"
                :post="post"
            />
            <p
                v-if="props.posts.data.length === 0"
                class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                No posts yet.
            </p>
            <SimplePagination :paginator="props.posts" />
        </section>

        <!-- Activity -->
        <section class="space-y-3">
            <h2
                class="text-sm font-semibold tracking-widest text-muted-foreground uppercase"
            >
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
