<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Languages } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { productAnalyticsMessages } from '@/lib/productAnalyticsMessages';

/**
 * One anonymous installation, as a story rather than a percentage.
 *
 * The point of this page is the drop-off you cannot see in an aggregate: a
 * wallet created and never funded, a swap quoted six times and never signed, a
 * bridge deposit that broadcast and never registered. All of it is already in
 * the tables — this only puts it in order.
 *
 * What it deliberately does not show is the linked addresses. They exist so
 * the server can verify funding and price a sponsored drip, neither of which
 * anybody does by eye; printing them would turn a debugging screen into a way
 * of matching an on-chain identity to a visitor, which is the one thing this
 * whole design is built to avoid. The count is shown instead.
 */

type TimelineRow = {
    event: string;
    chain: string | null;
    properties: Record<string, string | number | boolean> | null;
    created_at: string;
    session_id: string | null;
};

const props = defineProps<{
    user: {
        id: string;
        first_seen_at: string | null;
        last_seen_at: string | null;
        platform: string | null;
        app_version: string | null;
        language: string | null;
        source: string | null;
        medium: string | null;
        campaign: string | null;
        content: string | null;
        referrer: string | null;
        landing_path: string | null;
        wallet_created_at: string | null;
        wallet_origin: string | null;
        funded_at: string | null;
        funded_chain: string | null;
        funded_source: string | null;
        activated_at: string | null;
        activation_event: string | null;
        first_transaction_at: string | null;
        linked_addresses: number;
    };
    timeline: TimelineRow[];
    sessions: {
        id: string;
        started_at: string;
        last_activity_at: string;
        ended_at: string | null;
        platform: string | null;
        app_version: string | null;
    }[];
    meaningful: string[];
}>();

const { nextTag, toggleLocale, t } = useLocale(productAnalyticsMessages);

const stamp = (value: string | null): string =>
    value === null ? '—' : value.replace('T', ' ').slice(0, 19);

const shortId = (id: string | null): string => (id === null ? '—' : id.slice(0, 8));

const isMeaningful = (event: string): boolean => props.meaningful.includes(event);

/** Properties as one line, so a row stays a row. */
const summarize = (
    properties: Record<string, string | number | boolean> | null,
): string =>
    properties === null
        ? ''
        : Object.entries(properties)
              .filter(([key]) => key !== 'chain')
              .map(([key, value]) => `${key}=${value}`)
              .join(' · ');

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'CRM', href: '/crm' },
            { title: 'Wallet analytics', href: '/crm/product' },
            { title: 'Installation', href: '#' },
        ],
    }),
});
</script>

<template>
    <Head :title="`Installation ${shortId(user.id)}`" />

    <div class="m-2 flex flex-col space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <Link href="/crm/product">
                    <Button variant="ghost" size="sm">
                        <ArrowLeft class="h-4 w-4" />
                    </Button>
                </Link>
                <Heading
                    variant="small"
                    :title="`${t('userTitle')} ${shortId(user.id)}`"
                    :description="user.id"
                />
            </div>
            <Button variant="ghost" size="sm" @click="toggleLocale">
                <Languages class="h-4 w-4" />
                {{ nextTag }}
            </Button>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- Who and where from -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('attribution') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div
                        v-for="row in [
                            { key: 'firstSeen', value: stamp(user.first_seen_at) },
                            { key: 'lastSeen', value: stamp(user.last_seen_at) },
                            { key: 'platform', value: user.platform ?? '—' },
                            {
                                key: 'appVersion',
                                value: user.app_version ?? '—',
                            },
                            { key: 'language', value: user.language ?? '—' },
                            {
                                key: 'source',
                                value: user.source ?? t('direct'),
                            },
                            { key: 'campaign', value: user.campaign ?? '—' },
                            { key: 'referrer', value: user.referrer ?? '—' },
                            {
                                key: 'landingPath',
                                value: user.landing_path ?? '—',
                            },
                        ]"
                        :key="row.key"
                        class="flex items-center justify-between gap-3 border-b border-border/40 pb-1.5 last:border-0"
                    >
                        <span class="text-muted-foreground">{{ t(row.key) }}</span>
                        <span class="truncate font-mono text-xs">{{
                            row.value
                        }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Milestones -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('milestones') }}</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div
                        v-for="row in [
                            {
                                key: 'walletCreated',
                                value: user.wallet_created_at
                                    ? `${stamp(user.wallet_created_at)} · ${user.wallet_origin}`
                                    : '—',
                            },
                            {
                                key: 'funded',
                                value: user.funded_at
                                    ? `${stamp(user.funded_at)} · ${user.funded_chain} · ${user.funded_source}`
                                    : '—',
                            },
                            {
                                key: 'activated',
                                value: user.activated_at
                                    ? `${stamp(user.activated_at)} · ${user.activation_event}`
                                    : '—',
                            },
                            {
                                key: 'firstTransaction',
                                value: stamp(user.first_transaction_at),
                            },
                            {
                                key: 'linkedAddresses',
                                value: String(user.linked_addresses),
                            },
                        ]"
                        :key="row.key"
                        class="flex items-center justify-between gap-3 border-b border-border/40 pb-1.5 last:border-0"
                    >
                        <span class="text-muted-foreground">{{ t(row.key) }}</span>
                        <span class="truncate font-mono text-xs">{{
                            row.value
                        }}</span>
                    </div>
                    <p class="text-[11px] leading-snug text-muted-foreground">
                        {{ t('linkedAddressesHint') }}
                    </p>
                </CardContent>
            </Card>

            <!-- Sessions -->
            <Card>
                <CardHeader>
                    <CardTitle>{{ t('sessions') }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="sessions.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('noData') }}
                    </p>
                    <div
                        v-for="session in sessions"
                        v-else
                        :key="session.id"
                        class="flex items-center justify-between gap-3 border-b border-border/40 py-1.5 font-mono text-xs last:border-0"
                    >
                        <span>{{ shortId(session.id) }}</span>
                        <span class="text-muted-foreground">
                            {{ stamp(session.started_at) }}
                        </span>
                        <span
                            :class="
                                session.ended_at
                                    ? 'text-muted-foreground'
                                    : 'text-primary'
                            "
                        >
                            {{ session.ended_at ? t('ended') : t('activeNow') }}
                        </span>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Timeline -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('timeline') }}</CardTitle>
            </CardHeader>
            <CardContent class="overflow-x-auto">
                <p
                    v-if="timeline.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('noEvents') }}
                </p>
                <table v-else class="w-full text-sm">
                    <tbody class="font-mono text-xs">
                        <tr
                            v-for="(row, index) in timeline"
                            :key="`${row.created_at}:${index}`"
                            class="border-t border-border/40"
                        >
                            <td class="w-44 py-2 text-muted-foreground">
                                {{ stamp(row.created_at) }}
                            </td>
                            <td class="w-56 py-2">
                                {{ row.event }}
                                <Badge
                                    v-if="isMeaningful(row.event)"
                                    variant="secondary"
                                    class="ml-2"
                                >
                                    {{ t('meaningfulMark') }}
                                </Badge>
                            </td>
                            <td class="w-24 py-2 text-muted-foreground">
                                {{ row.chain ?? '' }}
                            </td>
                            <td class="py-2 text-muted-foreground">
                                {{ summarize(row.properties) }}
                            </td>
                            <td class="w-20 py-2 text-right text-muted-foreground">
                                {{ shortId(row.session_id) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>
    </div>
</template>
