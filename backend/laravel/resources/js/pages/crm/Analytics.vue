<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Languages } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { crmMessages } from '@/lib/crmMessages';

type Funnel = {
    visitors: number;
    wallets: number;
    bridge: number;
    swaps: number;
    liquidity: number;
};

type OnchainRow = { count: number; actors: number; usd: number } | null;

type DailyRow = { day: string; visitors: number };

type Cohort = {
    week: string;
    size: number;
    /** null until the cohort is old enough for that bucket to be honest. */
    rates: { d1: number | null; d7: number | null; d30: number | null };
};

type Progression = {
    tracked: number;
    with_xp: number;
    live_streaks: number;
    streaks_over_week: number;
    longest_streak: number;
    levels: { level: number; accounts: number }[];
};

type TopMember = {
    user_id: number;
    name: string | null;
    wallet_address: string | null;
    xp: number;
    level: number;
    current_streak: number;
    last_active_on: string | null;
};

type RecentEvent = {
    id: number;
    session_id: string;
    wallet_address: string | null;
    event: string;
    page: string | null;
    created_at: string;
    user: { id: number; name: string } | null;
};

const props = defineProps<{
    days: number;
    funnel: Funnel;
    onchain: {
        swaps: OnchainRow;
        liq_adds: OnchainRow;
        bridge_completed: number;
    };
    daily: DailyRow[];
    recent: RecentEvent[];
    activity: { dau: number; wau: number; mau: number; stickiness: number };
    newVsReturning: { new: number; returning: number };
    cohorts: Cohort[];
    progression: Progression;
    topMembers: TopMember[];
}>();

const { nextTag, toggleLocale, t } = useLocale(crmMessages);

const steps = computed(() => [
    { key: 'fVisitors', value: props.funnel.visitors },
    { key: 'fWallets', value: props.funnel.wallets },
    { key: 'fBridge', value: props.funnel.bridge },
    { key: 'fSwaps', value: props.funnel.swaps },
    { key: 'fLiquidity', value: props.funnel.liquidity },
]);

const maxStep = computed(() => Math.max(1, ...steps.value.map((s) => s.value)));

const pctOfVisitors = (value: number): string =>
    props.funnel.visitors > 0
        ? `${((value / props.funnel.visitors) * 100).toFixed(1)}%`
        : '—';

const maxDaily = computed(() =>
    Math.max(1, ...props.daily.map((d) => d.visitors)),
);

function setDays(days: number) {
    router.get(
        '/crm/analytics',
        { days },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

const formatUsd = (value: number): string =>
    `$${Number(value).toLocaleString('en-US', { maximumFractionDigits: 2 })}`;

const shortSession = (id: string): string => id.slice(0, 8);

const memberName = (member: TopMember): string =>
    member.name ||
    (member.wallet_address
        ? `${member.wallet_address.slice(0, 6)}…${member.wallet_address.slice(-4)}`
        : `User #${member.user_id}`);

const returningPct = computed(() => {
    const total = props.newVsReturning.new + props.newVsReturning.returning;

    return total > 0
        ? `${((props.newVsReturning.returning / total) * 100).toFixed(1)}%`
        : '—';
});

const maxLevelAccounts = computed(() =>
    Math.max(1, ...props.progression.levels.map((l) => l.accounts)),
);

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'CRM', href: '/crm' },
            { title: 'Analytics', href: '/crm/analytics' },
        ],
    }),
});
</script>

<template>
    <Head title="CRM Analytics" />

    <div class="m-2 flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <Link href="/crm">
                    <Button variant="ghost" size="sm">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <Heading
                    variant="small"
                    :title="t('analytics')"
                    :description="t('analyticsDescription')"
                />
            </div>
            <div class="flex items-center gap-2">
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="h-4 w-4" />
                    {{ nextTag }}
                </Button>
                <Button
                    v-for="window in [7, 30, 90]"
                    :key="window"
                    size="sm"
                    :variant="days === window ? 'default' : 'outline'"
                    @click="setDays(window)"
                >
                    {{ t(`days${window}`) }}
                </Button>
            </div>
        </div>

        <!-- Funnel -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('funnelTitle') }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="step in steps"
                    :key="step.key"
                    class="grid grid-cols-[14rem_1fr_7rem] items-center gap-3 text-sm"
                >
                    <span>{{ t(step.key) }}</span>
                    <div class="h-5 overflow-hidden rounded bg-muted/40">
                        <div
                            class="h-full rounded bg-primary/70"
                            :style="{
                                width: (step.value / maxStep) * 100 + '%',
                            }"
                        />
                    </div>
                    <span class="text-right font-mono text-xs">
                        {{ step.value }}
                        <span class="text-muted-foreground">
                            · {{ pctOfVisitors(step.value) }}
                        </span>
                    </span>
                </div>
            </CardContent>
        </Card>

        <!-- Retention: active devices, loyalty split -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('retentionTitle') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <div
                    class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                >
                    <div
                        v-for="tile in [
                            { key: 'dau', value: activity.dau },
                            { key: 'wau', value: activity.wau },
                            { key: 'mau', value: activity.mau },
                            {
                                key: 'stickiness',
                                value: `${activity.stickiness}%`,
                                hint: t('stickinessHint'),
                            },
                            {
                                key: 'newDevices',
                                value: newVsReturning.new,
                            },
                            {
                                key: 'returningDevices',
                                value: newVsReturning.returning,
                                hint: returningPct,
                            },
                        ]"
                        :key="tile.key"
                        class="rounded-lg border border-border/70 p-3"
                    >
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t(tile.key) }}
                        </p>
                        <p class="mt-1 font-mono text-xl font-bold">
                            {{ tile.value }}
                        </p>
                        <p
                            v-if="tile.hint"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ tile.hint }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Acquisition cohorts -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('cohortsTitle') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="cohorts.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('noCohorts') }}
                    </p>
                    <table v-else class="w-full text-sm">
                        <thead
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="py-1 text-left">
                                    {{ t('cohortWeek') }}
                                </th>
                                <th class="py-1 text-right">
                                    {{ t('cohortSize') }}
                                </th>
                                <th class="py-1 text-right">D1</th>
                                <th class="py-1 text-right">D7</th>
                                <th class="py-1 text-right">D30</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="cohort in cohorts"
                                :key="cohort.week"
                                class="border-t border-border/60"
                            >
                                <td class="py-1.5 font-mono text-xs">
                                    {{ cohort.week }}
                                </td>
                                <td class="py-1.5 text-right font-mono text-xs">
                                    {{ cohort.size }}
                                </td>
                                <td
                                    v-for="bucket in [
                                        cohort.rates.d1,
                                        cohort.rates.d7,
                                        cohort.rates.d30,
                                    ]"
                                    :key="`${cohort.week}-${bucket}`"
                                    class="py-1.5 text-right font-mono text-xs"
                                    :class="
                                        bucket === null
                                            ? 'text-muted-foreground/60'
                                            : ''
                                    "
                                >
                                    {{
                                        bucket === null
                                            ? t('notMature')
                                            : `${bucket}%`
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs text-muted-foreground">
                        {{ t('cohortsHint') }}
                    </p>
                </CardContent>
            </Card>

            <!-- Progression health -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('progressionTitle') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div
                            v-for="tile in [
                                { key: 'tracked', value: progression.tracked },
                                { key: 'withXp', value: progression.with_xp },
                                {
                                    key: 'liveStreaks',
                                    value: progression.live_streaks,
                                },
                                {
                                    key: 'streaksOverWeek',
                                    value: progression.streaks_over_week,
                                },
                                {
                                    key: 'longestStreak',
                                    value: progression.longest_streak,
                                },
                            ]"
                            :key="tile.key"
                            class="rounded-lg border border-border/70 p-3"
                        >
                            <p
                                class="text-xs tracking-widest text-muted-foreground uppercase"
                            >
                                {{ t(tile.key) }}
                            </p>
                            <p class="mt-1 font-mono text-xl font-bold">
                                {{ tile.value }}
                            </p>
                        </div>
                    </div>

                    <div v-if="progression.levels.length > 0">
                        <p
                            class="mb-2 text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('levelSpread') }}
                        </p>
                        <div
                            v-for="row in progression.levels"
                            :key="row.level"
                            class="grid grid-cols-[3rem_1fr_3rem] items-center gap-2 text-xs"
                        >
                            <span class="font-mono">L{{ row.level }}</span>
                            <div
                                class="h-3 overflow-hidden rounded bg-muted/40"
                            >
                                <div
                                    class="h-full rounded bg-primary/70"
                                    :style="{
                                        width:
                                            (row.accounts / maxLevelAccounts) *
                                                100 +
                                            '%',
                                    }"
                                />
                            </div>
                            <span class="text-right font-mono">{{
                                row.accounts
                            }}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Most engaged members -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('topMembersTitle') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="topMembers.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('noMembers') }}
                </p>
                <table v-else class="w-full text-sm">
                    <thead
                        class="text-xs tracking-widest text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="py-1 text-left">{{ t('colUser') }}</th>
                            <th class="py-1 text-right">{{ t('colLevel') }}</th>
                            <th class="py-1 text-right">{{ t('colXp') }}</th>
                            <th class="py-1 text-right">
                                {{ t('colStreak') }}
                            </th>
                            <th class="py-1 text-right">
                                {{ t('colLastSeen') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="member in topMembers"
                            :key="member.user_id"
                            class="border-t border-border/60"
                        >
                            <td class="py-1.5">
                                <Link
                                    :href="`/u/${member.user_id}`"
                                    class="hover:underline"
                                >
                                    {{ memberName(member) }}
                                </Link>
                            </td>
                            <td class="py-1.5 text-right font-mono text-xs">
                                {{ member.level }}
                            </td>
                            <td class="py-1.5 text-right font-mono text-xs">
                                {{ member.xp.toLocaleString() }}
                            </td>
                            <td class="py-1.5 text-right font-mono text-xs">
                                {{ member.current_streak }}
                            </td>
                            <td
                                class="py-1.5 text-right font-mono text-xs text-muted-foreground"
                            >
                                {{ member.last_active_on ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- On-chain ground truth -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('onchainTitle') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span>{{ t('ocSwaps') }}</span>
                        <span class="font-mono text-xs">
                            {{ onchain.swaps?.count ?? 0 }}
                            · {{ onchain.swaps?.actors ?? 0 }}
                            {{ t('actors') }}
                            · {{ formatUsd(onchain.swaps?.usd ?? 0) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>{{ t('ocLiq') }}</span>
                        <span class="font-mono text-xs">
                            {{ onchain.liq_adds?.count ?? 0 }}
                            · {{ onchain.liq_adds?.actors ?? 0 }}
                            {{ t('actors') }}
                            · {{ formatUsd(onchain.liq_adds?.usd ?? 0) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span>{{ t('ocBridge') }}</span>
                        <span class="font-mono text-xs">
                            {{ onchain.bridge_completed }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- Daily visitors -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('dailyTitle') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-1">
                    <div
                        v-for="d in daily"
                        :key="d.day"
                        class="grid grid-cols-[6rem_1fr_3rem] items-center gap-2 text-xs"
                    >
                        <span class="font-mono text-muted-foreground">
                            {{ d.day }}
                        </span>
                        <div class="h-3 overflow-hidden rounded bg-muted/40">
                            <div
                                class="h-full rounded bg-primary/70"
                                :style="{
                                    width: (d.visitors / maxDaily) * 100 + '%',
                                }"
                            />
                        </div>
                        <span class="text-right font-mono">
                            {{ d.visitors }}
                        </span>
                    </div>
                    <p
                        v-if="daily.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('noEvents') }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Recent events -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('recentTitle') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b text-left text-muted-foreground">
                            <tr>
                                <th class="px-2 py-1 font-medium">
                                    {{ t('colEvent') }}
                                </th>
                                <th class="px-2 py-1 font-medium">
                                    {{ t('colPage') }}
                                </th>
                                <th class="px-2 py-1 font-medium">
                                    {{ t('colUser') }}
                                </th>
                                <th class="px-2 py-1 font-medium">
                                    {{ t('colWhen') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="event in recent"
                                :key="event.id"
                                class="border-b last:border-0"
                            >
                                <td class="px-2 py-1">
                                    <Badge variant="outline">
                                        {{ t(`event.${event.event}`) }}
                                    </Badge>
                                </td>
                                <td class="px-2 py-1 font-mono text-xs">
                                    {{ event.page || '—' }}
                                </td>
                                <td class="px-2 py-1 text-xs">
                                    {{
                                        event.user?.name ||
                                        event.wallet_address ||
                                        `${t('anonymous')} ·
                                        ${shortSession(event.session_id)}`
                                    }}
                                </td>
                                <td
                                    class="px-2 py-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{
                                        event.created_at
                                            .slice(0, 16)
                                            .replace('T', ' ')
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="recent.length === 0"
                        class="py-4 text-sm text-muted-foreground"
                    >
                        {{ t('noEvents') }}
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
