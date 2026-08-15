<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Flame, Languages } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import PageHero from '@/components/web3/PageHero.vue';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { useLocale } from '@/composables/useLocale';
import { progressMessages } from '@/lib/progressMessages';
import type { LeaderboardRow, PublicProgress } from '@/types/progress';

const props = defineProps<{
    rows: LeaderboardRow[];
    me: (PublicProgress & { user_id: number }) | null;
}>();

const { nextTag, toggleLocale, t } = useLocale(progressMessages);

const displayName = (row: {
    name: string | null;
    wallet_address: string | null;
    user_id: number;
}): string =>
    row.name ||
    (row.wallet_address
        ? `${row.wallet_address.slice(0, 6)}…${row.wallet_address.slice(-4)}`
        : `User #${row.user_id}`);

/** True when the signed-in user is outside the rendered top-N. */
const showOwnRow = computed(
    () =>
        props.me !== null &&
        props.me.rank !== null &&
        !props.rows.some((row) => row.user_id === props.me?.user_id),
);
</script>

<template>
    <Head :title="t('leaderboard')" />

    <div class="mx-auto max-w-3xl space-y-8 p-6">
        <PageHero
            eyebrow="XP"
            :title="t('leaderboard')"
            :description="t('leaderboardIntro')"
        >
            <template #actions>
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="mr-1 size-4" />
                    {{ nextTag }}
                </Button>
            </template>
        </PageHero>

        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
            {{ t('emptyBoard') }}
        </p>

        <ol
            v-else
            class="divide-y divide-border/60 rounded-lg border border-border/70"
        >
            <li
                v-for="row in rows"
                :key="row.user_id"
                class="flex items-center gap-3 p-3"
                :class="row.user_id === me?.user_id ? 'bg-brand-cyan/5' : ''"
            >
                <span
                    class="w-8 shrink-0 text-right font-mono text-sm font-bold"
                    :class="
                        row.position <= 3
                            ? 'text-brand-cyan'
                            : 'text-muted-foreground'
                    "
                >
                    {{ row.position }}
                </span>

                <WalletAvatar
                    :seed="row.wallet_address"
                    :name="row.name"
                    :src="row.avatar"
                    size="sm"
                />

                <Link
                    :href="row.profile_url"
                    class="min-w-0 flex-1 hover:underline"
                >
                    <span class="block truncate font-semibold">
                        {{ displayName(row) }}
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ t('level') }} {{ row.level }} · {{ row.title }}
                    </span>
                </Link>

                <span
                    v-if="row.current_streak > 1"
                    class="hidden shrink-0 items-center gap-1 font-mono text-xs text-muted-foreground sm:flex"
                >
                    <Flame class="size-3.5" />{{ row.current_streak }}
                </span>

                <span class="shrink-0 font-mono text-sm font-bold">
                    {{ row.xp.toLocaleString() }}
                    <span class="text-xs text-muted-foreground">{{
                        t('xp')
                    }}</span>
                </span>
            </li>
        </ol>

        <section
            v-if="showOwnRow && me"
            class="rounded-lg border border-brand-cyan/40 bg-brand-cyan/5 p-4"
        >
            <p class="text-xs tracking-widest text-muted-foreground uppercase">
                {{ t('yourStanding') }}
            </p>
            <p class="mt-1 font-mono text-lg font-bold">
                #{{ me.rank }} · {{ t('level') }} {{ me.level }} ·
                {{ me.xp.toLocaleString() }} {{ t('xp') }}
            </p>
        </section>
    </div>
</template>
