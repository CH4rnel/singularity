<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Languages } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { productAnalyticsMessages } from '@/lib/productAnalyticsMessages';

/**
 * The wallet's product dashboard.
 *
 * Ten numbers at the top and seven sections below, and every one of them is
 * here because somebody would change something on the strength of it. There is
 * no tile for "total events", no tile for page views, and no chart that only
 * ever goes up: a vanity metric costs the same screen space as a real one and
 * teaches the reader to stop looking.
 *
 * Definitions are printed next to the numbers rather than kept in a document.
 * "Active" here means a settled on-chain action and not an app that was
 * opened, which is exactly the sort of thing two people will otherwise read
 * two different ways for a quarter.
 */

type Filters = {
    from: string;
    to: string;
    days: number;
    platform: string | null;
    app_version: string | null;
    source: string | null;
    campaign: string | null;
    chain: string | null;
};

type Step = {
    key: string;
    value: number;
    of_top: number | null;
    of_previous: number | null;
};

type Cohort = {
    week: string;
    size: number;
    rates: { d1: number | null; d7: number | null; d30: number | null };
};

type AcquisitionRow = {
    source: string;
    campaign: string;
    users: number;
    wallets: number;
    funded: number;
    activated: number;
    activation_rate: number | null;
    d1: number | null;
    d7: number | null;
};

type UsageRow = {
    feature: string;
    users: number;
    actions: number;
    volume_usd: number;
    success_rate: number | null;
    failures: number;
};

type RecentUser = {
    id: string;
    created_at: string | null;
    last_seen_at: string | null;
    platform: string | null;
    app_version: string | null;
    source: string | null;
    campaign: string | null;
    wallet: boolean;
    funded: boolean;
    activated: boolean;
};

const props = defineProps<{
    filters: Filters;
    options: {
        platforms: string[];
        app_versions: string[];
        sources: string[];
        campaigns: string[];
        chains: string[];
    };
    overview: {
        north_star: number;
        new_users: number;
        wallets: number;
        funded_users: number;
        activated_users: number;
        dau: number;
        wau: number;
        mau: number;
        returning_users: number;
        activation_rate: number | null;
        funded_rate: number | null;
        transaction_success_rate: number | null;
        error_rate: number | null;
        swap_volume_usd: number;
        bridge_volume_usd: number;
        sponsored_gas_usd: number | null;
        d7_retention: number | null;
    };
    series: { day: string; opened: number; active: number; new: number }[];
    mainFunnel: Step[];
    productFunnels: Record<string, Step[]>;
    activation: {
        cohort: number;
        funded: number;
        activated: number;
        funded_rate: number | null;
        activation_rate: number | null;
        median_seconds_to_funding: number | null;
        median_seconds_to_first_transaction: number | null;
        funded_onchain: number;
        funded_claimed: number;
    };
    cohorts: Cohort[];
    acquisition: AcquisitionRow[];
    usage: UsageRow[];
    errors: {
        event: string;
        error_code: string;
        total: number;
        users: number;
    }[];
    gas: {
        transactions: number;
        addresses: number;
        sponsored_users: number;
        total_cyber: number;
        total_usd: number | null;
        cyber_price: number | null;
        usd_per_sponsored_user: number | null;
        usd_per_activated_user: number | null;
        requested: number;
        failed: number;
        success_rate: number | null;
        grounds: Record<string, number>;
    };
    recent: RecentUser[];
}>();

const { nextTag, toggleLocale, t } = useLocale(productAnalyticsMessages);

/* ------------------------------------------------------------- filters -- */

const query = (patch: Record<string, string | number | null>): void => {
    const next: Record<string, string | number> = {};
    const current = {
        days: props.filters.days,
        platform: props.filters.platform,
        app_version: props.filters.app_version,
        source: props.filters.source,
        campaign: props.filters.campaign,
        chain: props.filters.chain,
        ...patch,
    };

    for (const [key, value] of Object.entries(current)) {
        if (value !== null && value !== '') {
            next[key] = value;
        }
    }

    router.get('/crm/product', next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const FILTERS = [
    { key: 'platform', options: 'platforms' },
    { key: 'app_version', options: 'app_versions' },
    { key: 'source', options: 'sources' },
    { key: 'campaign', options: 'campaigns' },
    { key: 'chain', options: 'chains' },
] as const;

/* ------------------------------------------------------------ formats -- */

const pct = (value: number | null): string =>
    value === null ? '—' : `${value.toFixed(1)}%`;

const usd = (value: number | null): string =>
    value === null
        ? '—'
        : `$${value.toLocaleString('en-US', { maximumFractionDigits: 2 })}`;

/**
 * A duration in the largest unit that still says something. "3.2 h" is a
 * sentence about onboarding; "11,517 s" is a number nobody reads twice.
 */
const duration = (seconds: number | null): string => {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 90) {
        return `${seconds}s`;
    }

    if (seconds < 5_400) {
        return `${(seconds / 60).toFixed(1)}m`;
    }

    return seconds < 172_800
        ? `${(seconds / 3_600).toFixed(1)}h`
        : `${(seconds / 86_400).toFixed(1)}d`;
};

const shortId = (id: string): string => id.slice(0, 8);

const day = (value: string | null): string =>
    value === null ? '—' : value.slice(0, 10);

/* -------------------------------------------------------------- tiles -- */

const tiles = computed(() => [
    {
        key: 'northStar',
        value: props.overview.north_star,
        hint: t('northStarHint'),
        lead: true,
    },
    { key: 'newUsers', value: props.overview.new_users, hint: t('newUsersHint') },
    {
        key: 'activatedUsers',
        value: props.overview.activated_users,
        hint: t('activatedHint'),
    },
    { key: 'wau', value: props.overview.wau, hint: t('wauHint') },
    {
        key: 'd7Retention',
        value: pct(props.overview.d7_retention),
        hint: t('d7Hint'),
    },
    {
        key: 'activationRate',
        value: pct(props.overview.activation_rate),
        hint: null,
    },
    {
        key: 'txSuccess',
        value: pct(props.overview.transaction_success_rate),
        hint: t('txSuccessHint'),
    },
    { key: 'swapVolume', value: usd(props.overview.swap_volume_usd), hint: null },
    {
        key: 'bridgeVolume',
        value: usd(props.overview.bridge_volume_usd),
        hint: null,
    },
    {
        key: 'sponsoredGas',
        value: usd(props.overview.sponsored_gas_usd),
        hint: t('sponsoredGasHint'),
    },
]);

const maxSeries = computed(() =>
    Math.max(1, ...props.series.map((row) => Math.max(row.opened, row.new))),
);

const maxFunnel = computed(() =>
    Math.max(1, ...props.mainFunnel.map((step) => step.value)),
);

const funnelMax = (steps: Step[]): number =>
    Math.max(1, ...steps.map((step) => step.value));

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'CRM', href: '/crm' },
            { title: 'Wallet analytics', href: '/crm/product' },
        ],
    }),
});
</script>

<template>
    <Head title="Wallet analytics" />

    <div class="m-2 flex flex-col space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/crm">
                    <Button variant="ghost" size="sm">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <Heading
                    variant="small"
                    :title="t('title')"
                    :description="t('description')"
                />
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link href="/crm/analytics">
                    <Button variant="outline" size="sm">
                        {{ t('siteFunnel') }}
                    </Button>
                </Link>
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="h-4 w-4" />
                    {{ nextTag }}
                </Button>
                <Button
                    v-for="window in [7, 30, 90]"
                    :key="window"
                    size="sm"
                    :variant="filters.days === window ? 'default' : 'outline'"
                    @click="query({ days: window })"
                >
                    {{ t(`days${window}`) }}
                </Button>
            </div>
        </div>

        <!--
          Filters. Platform, version, source and campaign narrow the population
          and everything below is then measured inside it; chain narrows the
          activity without changing who is in the denominator.
        -->
        <Card>
            <CardContent class="flex flex-wrap items-end gap-3 pt-6">
                <div v-for="filter in FILTERS" :key="filter.key">
                    <label
                        class="mb-1 block text-xs tracking-widest text-muted-foreground uppercase"
                    >
                        {{ t(filter.key === 'app_version' ? 'appVersion' : filter.key) }}
                    </label>
                    <select
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                        :value="filters[filter.key] ?? ''"
                        @change="
                            query({
                                [filter.key]:
                                    ($event.target as HTMLSelectElement)
                                        .value || null,
                            })
                        "
                    >
                        <option value="">{{ t('any') }}</option>
                        <option
                            v-for="option in options[filter.options]"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </select>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    @click="
                        query({
                            platform: null,
                            app_version: null,
                            source: null,
                            campaign: null,
                            chain: null,
                        })
                    "
                >
                    {{ t('reset') }}
                </Button>
                <span class="ml-auto font-mono text-xs text-muted-foreground">
                    {{ filters.from }} → {{ filters.to }}
                </span>
            </CardContent>
        </Card>

        <!-- The headline row. North Star first and larger, because it is. -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <div
                v-for="tile in tiles"
                :key="tile.key"
                class="rounded-lg border p-3"
                :class="
                    tile.lead
                        ? 'border-primary/60 bg-primary/5'
                        : 'border-border/70'
                "
            >
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t(tile.key) }}
                </p>
                <p
                    class="mt-1 font-mono font-bold"
                    :class="tile.lead ? 'text-3xl' : 'text-xl'"
                >
                    {{ tile.value }}
                </p>
                <p
                    v-if="tile.hint"
                    class="mt-1 text-[11px] leading-snug text-muted-foreground"
                >
                    {{ tile.hint }}
                </p>
            </div>
        </div>

        <!-- 1. Active users over time -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('activeOverTime') }}</CardTitle>
            </CardHeader>
            <CardContent>
                <p v-if="series.length === 0" class="text-sm text-muted-foreground">
                    {{ t('noData') }}
                </p>
                <div v-else>
                    <div class="flex h-40 items-end gap-px">
                        <div
                            v-for="row in series"
                            :key="row.day"
                            class="relative flex-1"
                            :title="`${row.day} · ${t('opened')} ${row.opened} · ${t('active')} ${row.active} · ${t('newLine')} ${row.new}`"
                        >
                            <div
                                class="w-full rounded-t bg-muted"
                                :style="{
                                    height: `${(row.opened / maxSeries) * 150}px`,
                                }"
                            />
                            <div
                                class="absolute bottom-0 w-full rounded-t bg-primary/80"
                                :style="{
                                    height: `${(row.active / maxSeries) * 150}px`,
                                }"
                            />
                        </div>
                    </div>
                    <div
                        class="mt-2 flex items-center gap-4 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1">
                            <span class="inline-block h-2 w-3 bg-muted" />
                            {{ t('opened') }}
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="inline-block h-2 w-3 bg-primary/80" />
                            {{ t('active') }}
                        </span>
                        <span class="ml-auto font-mono">
                            {{ series[0]?.day }} → {{ series[series.length - 1]?.day }}
                        </span>
                    </div>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- 2. Main funnel -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('mainFunnel') }}</CardTitle>
                    <p class="text-xs text-muted-foreground">
                        {{ t('mainFunnelHint') }}
                    </p>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div
                        v-for="step in mainFunnel"
                        :key="step.key"
                        class="grid grid-cols-[10rem_1fr_8rem] items-center gap-3 text-sm"
                    >
                        <span>{{ t(`step_${step.key}`) }}</span>
                        <div class="h-5 overflow-hidden rounded bg-muted/40">
                            <div
                                class="h-full rounded bg-primary/70"
                                :style="{
                                    width: `${(step.value / maxFunnel) * 100}%`,
                                }"
                            />
                        </div>
                        <span class="text-right font-mono text-xs">
                            {{ step.value }}
                            <span class="text-muted-foreground">
                                · {{ pct(step.of_top) }}
                            </span>
                        </span>
                    </div>
                </CardContent>
            </Card>

            <!-- 3. Activation -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('activation') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div
                        v-for="row in [
                            { key: 'cohort', value: activation.cohort },
                            {
                                key: 'fundedRate',
                                value: `${pct(activation.funded_rate)} (${activation.funded})`,
                            },
                            {
                                key: 'activationRate',
                                value: `${pct(activation.activation_rate)} (${activation.activated})`,
                            },
                            {
                                key: 'medianToFunding',
                                value: duration(
                                    activation.median_seconds_to_funding,
                                ),
                            },
                            {
                                key: 'medianToFirstTx',
                                value: duration(
                                    activation.median_seconds_to_first_transaction,
                                ),
                            },
                            {
                                key: 'fundedOnchain',
                                value: activation.funded_onchain,
                            },
                            {
                                key: 'fundedClaimed',
                                value: activation.funded_claimed,
                            },
                        ]"
                        :key="row.key"
                        class="flex items-center justify-between border-b border-border/40 pb-2 last:border-0"
                    >
                        <span class="text-muted-foreground">{{ t(row.key) }}</span>
                        <span class="font-mono">{{ row.value }}</span>
                    </div>
                    <p class="text-[11px] text-muted-foreground">
                        {{ t('fundedSplitHint') }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- 4. Retention cohorts -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('retention') }}</CardTitle>
                <p class="text-xs text-muted-foreground">
                    {{ t('retentionHint') }}
                </p>
            </CardHeader>
            <CardContent>
                <p v-if="cohorts.length === 0" class="text-sm text-muted-foreground">
                    {{ t('noData') }}
                </p>
                <table v-else class="w-full text-sm">
                    <thead
                        class="text-left text-xs tracking-widest text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="pb-2">{{ t('week') }}</th>
                            <th class="pb-2 text-right">{{ t('size') }}</th>
                            <th class="pb-2 text-right">D1</th>
                            <th class="pb-2 text-right">D7</th>
                            <th class="pb-2 text-right">D30</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-xs">
                        <tr
                            v-for="cohort in cohorts"
                            :key="cohort.week"
                            class="border-t border-border/40"
                        >
                            <td class="py-2">{{ cohort.week }}</td>
                            <td class="py-2 text-right">{{ cohort.size }}</td>
                            <td class="py-2 text-right">
                                {{ pct(cohort.rates.d1) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ pct(cohort.rates.d7) }}
                            </td>
                            <td class="py-2 text-right">
                                {{ pct(cohort.rates.d30) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <!-- 5. Acquisition -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('acquisition') }}</CardTitle>
                <p class="text-xs text-muted-foreground">
                    {{ t('acquisitionHint') }}
                </p>
            </CardHeader>
            <CardContent>
                <p
                    v-if="acquisition.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('noData') }}
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-left text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="pb-2">{{ t('source') }}</th>
                                <th class="pb-2">{{ t('campaign') }}</th>
                                <th class="pb-2 text-right">{{ t('users') }}</th>
                                <th class="pb-2 text-right">
                                    {{ t('wallets') }}
                                </th>
                                <th class="pb-2 text-right">{{ t('funded') }}</th>
                                <th class="pb-2 text-right">
                                    {{ t('activated') }}
                                </th>
                                <th class="pb-2 text-right">
                                    {{ t('toActivation') }}
                                </th>
                                <th class="pb-2 text-right">D1</th>
                                <th class="pb-2 text-right">D7</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-xs">
                            <tr
                                v-for="row in acquisition"
                                :key="`${row.source}:${row.campaign}`"
                                class="border-t border-border/40"
                            >
                                <td class="py-2">{{ row.source }}</td>
                                <td class="py-2">{{ row.campaign }}</td>
                                <td class="py-2 text-right">{{ row.users }}</td>
                                <td class="py-2 text-right">
                                    {{ row.wallets }}
                                </td>
                                <td class="py-2 text-right">{{ row.funded }}</td>
                                <td class="py-2 text-right">
                                    {{ row.activated }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ pct(row.activation_rate) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ pct(row.d1) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ pct(row.d7) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- 6. Product usage -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('productUsage') }}</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="text-left text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="pb-2">{{ t('feature') }}</th>
                                <th class="pb-2 text-right">{{ t('users') }}</th>
                                <th class="pb-2 text-right">
                                    {{ t('actions') }}
                                </th>
                                <th class="pb-2 text-right">{{ t('volume') }}</th>
                                <th class="pb-2 text-right">
                                    {{ t('successRate') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-xs">
                            <tr
                                v-for="row in usage"
                                :key="row.feature"
                                class="border-t border-border/40"
                            >
                                <td class="py-2 font-sans">
                                    {{ t(`feature_${row.feature}`) }}
                                </td>
                                <td class="py-2 text-right">{{ row.users }}</td>
                                <td class="py-2 text-right">
                                    {{ row.actions }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ usd(row.volume_usd) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ pct(row.success_rate) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <!-- 7. Errors -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('errors') }}</CardTitle>
                    <p class="text-xs text-muted-foreground">
                        {{ t('errorsHint') }}
                    </p>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="errors.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('noData') }}
                    </p>
                    <table v-else class="w-full text-sm">
                        <thead
                            class="text-left text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="pb-2">{{ t('event') }}</th>
                                <th class="pb-2">{{ t('errorCode') }}</th>
                                <th class="pb-2 text-right">{{ t('count') }}</th>
                                <th class="pb-2 text-right">{{ t('users') }}</th>
                            </tr>
                        </thead>
                        <tbody class="font-mono text-xs">
                            <tr
                                v-for="row in errors"
                                :key="`${row.event}:${row.error_code}`"
                                class="border-t border-border/40"
                            >
                                <td class="py-2">{{ row.event }}</td>
                                <td class="py-2">{{ row.error_code }}</td>
                                <td class="py-2 text-right">{{ row.total }}</td>
                                <td class="py-2 text-right">{{ row.users }}</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>
        </div>

        <!-- Product funnels -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('productFunnels') }}</CardTitle>
                <p class="text-xs text-muted-foreground">
                    {{ t('funnelUsersHint') }}
                </p>
            </CardHeader>
            <CardContent class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <div v-for="(steps, name) in productFunnels" :key="name">
                    <p class="mb-2 text-sm font-medium">
                        {{ t(`funnel_${name}`) }}
                    </p>
                    <div
                        v-for="step in steps"
                        :key="step.key"
                        class="grid grid-cols-[1fr_2.5rem_3rem] items-center gap-2 py-0.5 text-xs"
                    >
                        <div class="h-4 overflow-hidden rounded bg-muted/40">
                            <div
                                class="h-full rounded bg-primary/60"
                                :style="{
                                    width: `${(step.value / funnelMax(steps)) * 100}%`,
                                }"
                            />
                        </div>
                        <span class="text-right font-mono">{{ step.value }}</span>
                        <span
                            class="text-right font-mono text-muted-foreground"
                            >{{ pct(step.of_previous) }}</span
                        >
                    </div>
                    <p class="mt-1 font-mono text-[10px] text-muted-foreground">
                        {{ steps.map((step) => step.key).join(' → ') }}
                    </p>
                </div>
            </CardContent>
        </Card>

        <!-- 8. Sponsored gas -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('gasTitle') }}</CardTitle>
                <p class="text-xs text-muted-foreground">{{ t('gasHint') }}</p>
            </CardHeader>
            <CardContent>
                <!--
                  Four across at most: seven of these on one row collides the
                  Russian labels into each other, and a label that overlaps its
                  neighbour is a number nobody can attribute.
                -->
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div
                        v-for="tile in [
                            { key: 'gasDrips', value: gas.transactions },
                            { key: 'gasUsers', value: gas.sponsored_users },
                            { key: 'gasTotal', value: usd(gas.total_usd) },
                            {
                                key: 'gasPerUser',
                                value: usd(gas.usd_per_sponsored_user),
                            },
                            {
                                key: 'gasPerActivated',
                                value: usd(gas.usd_per_activated_user),
                            },
                            { key: 'gasRequested', value: gas.requested },
                            { key: 'gasFailed', value: gas.failed },
                        ]"
                        :key="tile.key"
                        class="rounded-lg border border-border/70 p-3"
                    >
                        <p
                            class="text-xs leading-snug tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t(tile.key) }}
                        </p>
                        <p class="mt-1 font-mono text-lg font-bold">
                            {{ tile.value }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="gas.cyber_price === null && gas.transactions > 0"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    {{ t('gasNoPrice') }} · {{ gas.total_cyber }} CYBER
                </p>
            </CardContent>
        </Card>

        <!-- User explorer -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('recentUsers') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="recent.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('noData') }}
                </p>
                <table v-else class="w-full text-sm">
                    <thead
                        class="text-left text-xs tracking-widest text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="pb-2">{{ t('installation') }}</th>
                            <th class="pb-2">{{ t('firstSeen') }}</th>
                            <th class="pb-2">{{ t('platform') }}</th>
                            <th class="pb-2">{{ t('source') }}</th>
                            <th class="pb-2">{{ t('status') }}</th>
                            <th class="pb-2"></th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-xs">
                        <tr
                            v-for="user in recent"
                            :key="user.id"
                            class="border-t border-border/40"
                        >
                            <td class="py-2">{{ shortId(user.id) }}</td>
                            <td class="py-2">{{ day(user.created_at) }}</td>
                            <td class="py-2">
                                {{ user.platform ?? '—' }}
                                <span class="text-muted-foreground">
                                    {{ user.app_version ?? '' }}
                                </span>
                            </td>
                            <td class="py-2">
                                {{ user.source ?? t('direct') }}
                                <span
                                    v-if="user.campaign"
                                    class="text-muted-foreground"
                                >
                                    · {{ user.campaign }}
                                </span>
                            </td>
                            <td class="py-2">
                                <span :class="user.wallet ? '' : 'opacity-30'"
                                    >W</span
                                >
                                <span
                                    class="ml-1"
                                    :class="user.funded ? '' : 'opacity-30'"
                                    >F</span
                                >
                                <span
                                    class="ml-1"
                                    :class="user.activated ? '' : 'opacity-30'"
                                    >A</span
                                >
                            </td>
                            <td class="py-2 text-right">
                                <Link :href="`/crm/product/users/${user.id}`">
                                    <Button variant="ghost" size="sm">
                                        {{ t('open') }}
                                    </Button>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
