<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CalendarDays,
    CheckCircle2,
    CircleDashed,
    Clock,
    ExternalLink,
    RefreshCw,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PageHero from '@/components/web3/PageHero.vue';
import PageShell from '@/components/web3/PageShell.vue';
import { track } from '@/lib/track';
import { login } from '@/routes';
import { show as profile } from '@/routes/profile';

type SeasonState = 'upcoming' | 'open' | 'closed';
type FinisherStatus = 'pending' | 'approved' | 'rejected';

interface Season {
    key: string;
    title: string;
    state: SeasonState;
    starts_at: string;
    ends_at: string;
    min_swap_usd: number;
    min_liquidity_usd: number;
    return_after_hours: number;
    review_first: number;
    xp_complete: number;
}

interface Checkpoint {
    number: number;
    key: string;
    title: string;
    description: string;
    verifier: string;
    required: boolean;
    xp: number;
}

interface PassedCheckpoint {
    qualified_at: string | null;
    reference: string;
    explorer_url: string | null;
}

interface Progress {
    excluded: boolean;
    has_wallet: boolean;
    has_evm_wallet: boolean;
    checkpoints: Record<number, PassedCheckpoint>;
    xp_earned: number;
    finisher: {
        status: FinisherStatus;
        completed_at: string | null;
        position: number | null;
    } | null;
}

interface BoardRow {
    position: number;
    user_id: number;
    name: string | null;
    wallet_address: string | null;
    profile_url: string;
    completed_at: string;
    status: FinisherStatus;
}

const props = defineProps<{
    season: Season;
    checkpoints: Checkpoint[];
    me: Progress | null;
    leaderboard: BoardRow[];
    stats: {
        checkpoints: Record<number, number>;
        finishers: number;
        approved: number;
    };
}>();

const formatDate = (iso: string | null): string =>
    iso
        ? new Date(iso).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              timeZone: 'UTC',
          })
        : '—';

const formatDateTime = (iso: string | null): string =>
    iso
        ? `${new Date(iso).toLocaleString(undefined, {
              day: 'numeric',
              month: 'short',
              hour: '2-digit',
              minute: '2-digit',
              timeZone: 'UTC',
          })} UTC`
        : '—';

const usd = (value: number): string =>
    `$${value.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;

const stateLabel = computed(
    () =>
        ({
            upcoming: `Opens ${formatDate(props.season.starts_at)}`,
            open: `Open until ${formatDate(props.season.ends_at)}`,
            closed: `Closed ${formatDate(props.season.ends_at)}`,
        })[props.season.state],
);

const passed = (checkpoint: Checkpoint): PassedCheckpoint | null =>
    props.me?.checkpoints[checkpoint.number] ?? null;

const requiredCount = computed(
    () => props.checkpoints.filter((checkpoint) => checkpoint.required).length,
);

const passedCount = computed(
    () =>
        props.checkpoints.filter(
            (checkpoint) => checkpoint.required && passed(checkpoint),
        ).length,
);

const minimumFor = (checkpoint: Checkpoint): string | null => {
    if (checkpoint.key === 'swap') {
        return `Minimum ${usd(props.season.min_swap_usd)}`;
    }

    if (checkpoint.key === 'liquidity') {
        return `Minimum ${usd(props.season.min_liquidity_usd)}`;
    }

    if (checkpoint.key === 'return') {
        return `At least ${props.season.return_after_hours} h after your first`;
    }

    return null;
};

const finisherLabel = computed(() => {
    const finisher = props.me?.finisher;

    if (!finisher) {
        return null;
    }

    return {
        pending: `Finished #${finisher.position}. A person checks the first ${props.season.review_first} finishers before the badge is minted.`,
        approved: `Finished #${finisher.position}. You are a Pioneer: the completion XP is paid and the badge is minted to your wallet.`,
        rejected:
            'Your completion did not pass review. Checkpoint XP already paid stays.',
    }[finisher.status];
});

const displayName = (row: BoardRow): string =>
    row.name ||
    (row.wallet_address
        ? `${row.wallet_address.slice(0, 6)}…${row.wallet_address.slice(-4)}`
        : `User #${row.user_id}`);

const checking = ref(false);

const checkAgain = (): void => {
    router.reload({
        only: ['me', 'stats', 'leaderboard'],
        onStart: () => (checking.value = true),
        onFinish: () => (checking.value = false),
    });
};

onMounted(() => {
    track('landing_view', {
        metadata: {
            action_type: `pioneer_season_${props.season.state}`,
            campaign: 'cyberia_pioneer_season',
        },
    });
});
</script>

<template>
    <Head :title="`Cyberia ${season.title}`">
        <meta
            head-key="description"
            name="description"
            content="Cyberia Pioneer Season: five server-verified on-chain checkpoints — wallet, bridge, swap, liquidity or staking, and a return on another day. Rewards are a badge and XP, never tokens."
        />
        <link
            head-key="canonical"
            rel="canonical"
            href="https://cyberia.church/pioneer-season"
        />
        <meta
            head-key="robots"
            name="robots"
            content="index,follow,max-image-preview:large"
        />
    </Head>

    <PageShell size="wide">
        <template #hero>
            <PageHero
                eyebrow="Campaign"
                :title="`Cyberia ${season.title}`"
                description="Five checkpoints on real on-chain use, each checked by the server against the bridge's records and the chain indexer. The reward is the Pioneer badge and XP — status, not tokens."
            >
                <template #actions>
                    <Badge
                        variant="outline"
                        class="gap-2"
                        :class="
                            season.state === 'open'
                                ? 'border-brand-cyan/50'
                                : 'border-amber-500/40'
                        "
                    >
                        <CheckCircle2
                            v-if="season.state === 'open'"
                            class="size-3.5 text-brand-cyan"
                        />
                        <CircleDashed v-else class="size-3.5 text-amber-500" />
                        {{ stateLabel }}
                    </Badge>
                </template>
            </PageHero>
        </template>

        <section
            class="grid gap-3 rounded-xl border border-border/70 bg-card p-5 md:grid-cols-3"
        >
            <div class="flex gap-3">
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div>
                    <p class="font-medium">Server verified</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Only actions between
                        {{ formatDate(season.starts_at) }} and
                        {{ formatDate(season.ends_at) }} (UTC) count. Nothing
                        your browser says unlocks a checkpoint.
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                <BadgeCheck class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div>
                    <p class="font-medium">Status, not token promises</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        A badge in CyberiaProfile and XP. Nothing is paid for
                        buying, volume, follows or reposts.
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                <CalendarDays class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div>
                    <p class="font-medium">Come back another day</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        The fifth checkpoint is a qualifying action on a later
                        UTC date, at least {{ season.return_after_hours }} h
                        after your first.
                    </p>
                </div>
            </div>
        </section>

        <section
            class="mt-8 rounded-xl border border-brand-cyan/30 bg-brand-cyan/5 p-5"
            aria-labelledby="progress-heading"
        >
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 id="progress-heading" class="text-lg font-bold">
                        Your progress
                    </h2>

                    <p v-if="!me" class="mt-1 text-sm text-muted-foreground">
                        Sign in with a wallet to see which checkpoints you have
                        passed.
                    </p>
                    <p
                        v-else-if="me.excluded"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Team and treasury accounts do not take part in the
                        season.
                    </p>
                    <p
                        v-else-if="!me.has_wallet"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Bind a wallet to your profile first: every checkpoint is
                        matched by address.
                    </p>
                    <p
                        v-else-if="season.state === 'upcoming'"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Nothing is counted before
                        {{ formatDateTime(season.starts_at) }}.
                    </p>
                    <template v-else>
                        <p class="mt-1 font-mono text-2xl font-bold">
                            {{ passedCount }} / {{ requiredCount }}
                            <span
                                class="text-sm font-normal text-muted-foreground"
                            >
                                · {{ me.xp_earned.toLocaleString() }} XP
                            </span>
                        </p>
                        <p v-if="finisherLabel" class="mt-2 max-w-2xl text-sm">
                            {{ finisherLabel }}
                        </p>
                        <p
                            v-if="!me.has_evm_wallet"
                            class="mt-2 max-w-2xl text-xs text-muted-foreground"
                        >
                            Swaps, liquidity and the badge are matched to an EVM
                            address; yours has only a Solana wallet bound.
                        </p>
                    </template>
                </div>

                <Button v-if="!me" as-child>
                    <Link :href="login().url">Sign in</Link>
                </Button>
                <Button v-else-if="!me.has_wallet" as-child variant="outline">
                    <a :href="profile().url">Open profile</a>
                </Button>
                <Button
                    v-else-if="season.state !== 'upcoming' && !me.excluded"
                    variant="outline"
                    :disabled="checking"
                    @click="checkAgain"
                >
                    <RefreshCw
                        class="mr-2 size-4"
                        :class="checking ? 'animate-spin' : ''"
                    />
                    Check again
                </Button>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="missions-heading">
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
                >
                    Checkpoints
                </p>
                <h2 id="missions-heading" class="mt-2 text-2xl font-bold">
                    Five verifiable checkpoints
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Each counts once per profile, however many wallets it holds,
                    and one transaction credits one profile. A swap reversed by
                    the same address within the hour, and a route that comes
                    back to the asset it started from, do not count. An amount
                    the indexer could not price does not count either.
                </p>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="checkpoint in checkpoints"
                    :key="checkpoint.key"
                    :class="passed(checkpoint) ? 'border-brand-cyan/50' : ''"
                >
                    <CardHeader>
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-mono text-xs text-brand-cyan">
                                {{ String(checkpoint.number).padStart(2, '0') }}
                            </p>
                            <Badge
                                v-if="!checkpoint.required"
                                variant="outline"
                            >
                                Not required this season
                            </Badge>
                            <Badge
                                v-else-if="passed(checkpoint)"
                                class="gap-1 bg-brand-cyan/15 text-brand-cyan"
                            >
                                <CheckCircle2 class="size-3.5" />
                                Passed
                            </Badge>
                            <Badge v-else variant="secondary">
                                {{ checkpoint.xp }} XP
                            </Badge>
                        </div>
                        <CardTitle class="text-lg">
                            {{ checkpoint.title }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            {{ checkpoint.description }}
                        </p>
                        <p
                            v-if="minimumFor(checkpoint)"
                            class="mt-2 font-mono text-xs"
                        >
                            {{ minimumFor(checkpoint) }}
                        </p>
                        <p class="mt-4 border-t border-border/70 pt-4 text-xs">
                            <span class="text-muted-foreground">Verifier:</span>
                            {{ checkpoint.verifier }}
                        </p>
                        <p
                            v-if="passed(checkpoint)"
                            class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <Clock class="size-3.5 shrink-0" />
                            {{
                                formatDateTime(passed(checkpoint)!.qualified_at)
                            }}
                            <a
                                v-if="passed(checkpoint)!.explorer_url"
                                :href="passed(checkpoint)!.explorer_url!"
                                target="_blank"
                                rel="noopener"
                                class="ml-auto inline-flex items-center gap-1 text-brand-cyan hover:underline"
                            >
                                Explorer
                                <ExternalLink class="size-3" />
                            </a>
                        </p>
                        <p
                            class="mt-3 text-xs text-muted-foreground"
                            :title="'Profiles that passed this checkpoint'"
                        >
                            {{ stats.checkpoints[checkpoint.number] ?? 0 }}
                            passed so far
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="board-heading">
            <div class="max-w-2xl">
                <h2 id="board-heading" class="text-2xl font-bold">
                    Pioneers, in the order they finished
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Ordered by when the last checkpoint happened on chain, not
                    by volume. The first {{ season.review_first }} are reviewed
                    by a person before the badge; finishing also pays
                    {{ season.xp_complete }} XP.
                </p>
            </div>

            <p
                v-if="leaderboard.length === 0"
                class="mt-6 text-sm text-muted-foreground"
            >
                Nobody has finished yet.
            </p>

            <ol
                v-else
                class="mt-6 divide-y divide-border/60 rounded-lg border border-border/70"
            >
                <li
                    v-for="row in leaderboard"
                    :key="row.user_id"
                    class="flex items-center gap-3 p-3"
                >
                    <span
                        class="w-8 shrink-0 text-right font-mono text-sm font-bold text-muted-foreground"
                    >
                        {{ row.position }}
                    </span>
                    <Link
                        :href="row.profile_url"
                        class="min-w-0 flex-1 truncate font-semibold hover:underline"
                    >
                        {{ displayName(row) }}
                    </Link>
                    <Badge
                        v-if="row.status === 'pending'"
                        variant="outline"
                        class="shrink-0"
                    >
                        Under review
                    </Badge>
                    <span
                        class="shrink-0 font-mono text-xs text-muted-foreground"
                    >
                        {{ formatDateTime(row.completed_at) }}
                    </span>
                </li>
            </ol>
        </section>

        <section
            class="mt-12 grid gap-6 rounded-xl border border-border/70 bg-muted/30 p-6 lg:grid-cols-[1fr_auto]"
        >
            <div>
                <h2 class="text-xl font-bold">What does not count</h2>
                <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                    Follows, reposts, holding a token and trading volume. Team
                    and treasury addresses take no part. There are no points
                    that turn into tokens later, and none of this is a promise
                    about any price.
                </p>
            </div>
            <Button as-child variant="outline" class="self-start">
                <a :href="profile().url">
                    View your achievements
                    <ExternalLink class="ml-2 size-4" />
                </a>
            </Button>
        </section>
    </PageShell>
</template>
