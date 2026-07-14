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
}>();

const { locale, toggleLocale, t } = useLocale(crmMessages);

const steps = computed(() => [
    { key: 'fVisitors', value: props.funnel.visitors },
    { key: 'fWallets', value: props.funnel.wallets },
    { key: 'fBridge', value: props.funnel.bridge },
    { key: 'fSwaps', value: props.funnel.swaps },
    { key: 'fLiquidity', value: props.funnel.liquidity },
]);

const maxStep = computed(() =>
    Math.max(1, ...steps.value.map((s) => s.value)),
);

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
                    {{ locale === 'ru' ? 'EN' : 'RU' }}
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
                        <thead
                            class="border-b text-left text-muted-foreground"
                        >
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
