<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Languages } from 'lucide-vue-next';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/composables/useLocale';
import { servicesMessages } from '@/lib/servicesMessages';

/**
 * The service board.
 *
 * Two questions on one page, kept visually apart because they lead to
 * different work: is it running, and is anyone using it. A service can be
 * perfectly healthy and used by nobody — that is a product decision, not an
 * outage — and the whole point of putting them side by side is that until now
 * neither question had an answer anywhere.
 *
 * Everything here is the last sweep, rendered. The page probes nothing itself:
 * a dashboard that runs its own checks disagrees with the alerts, is slow
 * exactly when the network is, and hammers production once somebody leaves it
 * open on a second screen.
 */

type Status = 'up' | 'degraded' | 'down' | 'unknown' | 'off';

type Usage = {
    measured: boolean;
    last_at: string | null;
    idle_days: number | null;
    count_7d: number | null;
    count_30d: number | null;
    actors_30d: number | null;
    unit: string | null;
};

type Service = {
    key: string;
    group: string;
    label: string;
    note: string | null;
    url: string | null;
    critical: boolean;
    deployed: boolean;
    probed: boolean;
    status: Status;
    reason: string | null;
    detail: Record<string, unknown> | null;
    latency_ms: number | null;
    checked_at: string | null;
    uptime_24h: number | null;
    uptime_7d: number | null;
    incident: {
        id: number;
        status: Status;
        reason: string | null;
        started_at: string | null;
        duration_seconds: number;
        notified: boolean;
    } | null;
    usage: Usage | null;
};

type Host = {
    host: string | null;
    reported_at: string | null;
    age_seconds: number | null;
    stale: boolean;
    metrics: {
        load: number[] | null;
        cpus: number | null;
        memory: { total_mb: number; available_mb: number } | null;
        swap: { total_mb: number; used_mb: number } | null;
        disk: { path: string; used_percent: number; free_gb: number } | null;
        uptime_seconds: number | null;
    };
    unregistered: string[];
};

type Incident = {
    id: number;
    service: string;
    label: string;
    status: Status;
    reason: string | null;
    started_at: string | null;
    resolved_at: string | null;
    duration_seconds: number;
};

const props = defineProps<{
    services: Service[];
    summary: {
        counts: Record<Status, number>;
        total: number;
        critical_down: number;
        measured: number;
    };
    hosts: Host[];
    incidents: Incident[];
    idle: Service[];
    settings: {
        stale_seconds: number;
        retention_days: number;
        alerts: boolean;
        heartbeat_configured: boolean;
    };
}>();

const { nextTag, toggleLocale, t } = useLocale(servicesMessages);

/* -------------------------------------------------------------- format -- */

/**
 * A duration in the largest unit that still says something. An incident
 * measured in seconds and one measured in days are read differently, and
 * "451,203 s" is read by nobody.
 */
const duration = (seconds: number): string => {
    if (seconds < 90) {
        return `${Math.round(seconds)}s`;
    }

    if (seconds < 5_400) {
        return `${Math.round(seconds / 60)}m`;
    }

    return seconds < 172_800
        ? `${(seconds / 3_600).toFixed(1)}h`
        : `${(seconds / 86_400).toFixed(1)}d`;
};

const ago = (iso: string | null): string => {
    if (iso === null) {
        return '—';
    }

    return duration((Date.now() - new Date(iso).getTime()) / 1000);
};

const pct = (value: number | null): string =>
    value === null
        ? '—'
        : `${value.toFixed(value >= 99.95 || value === 0 ? 0 : 2)}%`;

const num = (value: number | null | undefined): string =>
    value === null || value === undefined ? '—' : value.toLocaleString('en-US');

/* -------------------------------------------------------------- colour -- */

/**
 * One colour per state, used identically on dots, badges and tiles. Green,
 * amber and red carry the same meaning they carry everywhere; grey is used
 * for both "we don't know" and "deliberately off", which look different in
 * text and should never look alarming.
 */
const STATUS_CLASS: Record<Status, string> = {
    up: 'bg-emerald-500',
    degraded: 'bg-amber-500',
    down: 'bg-red-500',
    unknown: 'bg-muted-foreground/40',
    off: 'bg-muted-foreground/25',
};

const STATUS_TEXT: Record<Status, string> = {
    up: 'text-emerald-600 dark:text-emerald-400',
    degraded: 'text-amber-600 dark:text-amber-400',
    down: 'text-red-600 dark:text-red-400',
    unknown: 'text-muted-foreground',
    off: 'text-muted-foreground',
};

/* ------------------------------------------------------------ grouping -- */

const GROUP_ORDER = ['chain', 'web', 'infra', 'daemon', 'onchain', 'product'];

/** Worst-first, so a collapsed group can wear the worst state inside it. */
const SEVERITY: Record<Status, number> = {
    down: 4,
    degraded: 3,
    unknown: 2,
    off: 1,
    up: 0,
};

const groups = computed(() => {
    const seen = new Map<string, Service[]>();

    for (const service of props.services) {
        const bucket = seen.get(service.group) ?? [];
        bucket.push(service);
        seen.set(service.group, bucket);
    }

    return [...seen.entries()]
        .sort(
            (a, b) =>
                (GROUP_ORDER.indexOf(a[0]) + 1 || 99) -
                (GROUP_ORDER.indexOf(b[0]) + 1 || 99),
        )
        .map(([group, rows]) => ({
            group,
            rows,
            // A group's badge is the worst thing in it, so a collapsed
            // section still says whether it needs opening.
            worst: rows.reduce<Status>(
                (worst, row) =>
                    SEVERITY[row.status] > SEVERITY[worst] ? row.status : worst,
                'up',
            ),
        }));
});

const tiles = computed(() => [
    {
        key: 'tileDown',
        value: props.summary.counts.down ?? 0,
        status: 'down' as Status,
    },
    {
        key: 'tileDegraded',
        value: props.summary.counts.degraded ?? 0,
        status: 'degraded' as Status,
    },
    {
        key: 'tileHealthy',
        value: props.summary.counts.up ?? 0,
        status: 'up' as Status,
    },
    {
        key: 'tileUnknown',
        value: props.summary.counts.unknown ?? 0,
        status: 'unknown' as Status,
    },
    {
        key: 'tileOff',
        value: props.summary.counts.off ?? 0,
        status: 'off' as Status,
    },
    {
        key: 'tileCritical',
        value: props.summary.critical_down,
        status: (props.summary.critical_down > 0 ? 'down' : 'up') as Status,
    },
    {
        key: 'tileIdle',
        value: props.idle.length,
        status: (props.idle.length > 0 ? 'degraded' : 'up') as Status,
    },
]);

/** A service's usage cell: the number, or an honest reason there isn't one. */
const usageLabel = (service: Service): string => {
    if (service.usage === null || !service.usage.measured) {
        return t('unmeasured');
    }

    if (service.usage.count_30d === 0) {
        return service.usage.idle_days === null
            ? t('idleNever')
            : t('idleSince').replace('{days}', String(service.usage.idle_days));
    }

    return `${num(service.usage.count_7d)} / 7d · ${num(service.usage.count_30d)} / 30d`;
};

const freeMemory = (host: Host): number | null => {
    const memory = host.metrics.memory;

    return memory && memory.total_mb > 0
        ? Math.round((memory.available_mb / memory.total_mb) * 100)
        : null;
};

const swapUsed = (host: Host): number | null => {
    const swap = host.metrics.swap;

    return swap && swap.total_mb > 0
        ? Math.round((swap.used_mb / swap.total_mb) * 100)
        : null;
};

/** Everything the probe recorded, as one readable line under the row. */
const detailLine = (service: Service): string =>
    service.detail === null
        ? ''
        : Object.entries(service.detail)
              .filter(([, value]) => value !== null && value !== '')
              .map(
                  ([key, value]) =>
                      `${key}=${
                          typeof value === 'object'
                              ? JSON.stringify(value)
                              : String(value)
                      }`,
              )
              .join('  ');

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'CRM', href: '/crm' },
            { title: 'Services', href: '/crm/services' },
        ],
    }),
});
</script>

<template>
    <Head title="Services" />

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
                    <Button variant="outline" size="sm">{{
                        t('siteFunnel')
                    }}</Button>
                </Link>
                <Link href="/crm/product">
                    <Button variant="outline" size="sm">
                        {{ t('walletAnalytics') }}
                    </Button>
                </Link>
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="h-4 w-4" />
                    {{ nextTag }}
                </Button>
            </div>
        </div>

        <!--
          The heartbeat is the one piece of setup this page needs, and without
          it two thirds of the board reads `unknown`. So it is said once, in
          full, at the top rather than left to be inferred from a wall of grey.
        -->
        <Card
            v-if="hosts.length === 0 || !settings.heartbeat_configured"
            class="border-amber-500/50"
        >
            <CardHeader>
                <CardTitle class="text-amber-600 dark:text-amber-400">
                    {{ t('setupTitle') }}
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm text-muted-foreground">
                <p>{{ t('setupBody') }}</p>
                <p
                    v-if="!settings.heartbeat_configured"
                    class="font-mono text-xs"
                >
                    {{ t('setupNoToken') }}
                </p>
            </CardContent>
        </Card>

        <!-- Worst first: the two numbers that mean "act now" lead the row. -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
            <div
                v-for="tile in tiles"
                :key="tile.key"
                class="rounded-lg border border-border/70 p-3"
            >
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t(tile.key) }}
                </p>
                <p
                    class="mt-1 font-mono text-2xl font-bold"
                    :class="STATUS_TEXT[tile.status]"
                >
                    {{ tile.value }}
                </p>
            </div>
        </div>

        <!-- The board itself, one card per group. -->
        <Card v-for="group in groups" :key="group.group">
            <CardHeader class="flex flex-row items-center gap-2 space-y-0">
                <span
                    class="h-2 w-2 rounded-full"
                    :class="STATUS_CLASS[group.worst]"
                />
                <CardTitle>{{ t(`group.${group.group}`) }}</CardTitle>
                <span class="text-xs text-muted-foreground">{{
                    group.rows.length
                }}</span>
            </CardHeader>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full min-w-[52rem] text-sm">
                    <thead>
                        <tr
                            class="border-b text-left text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            <th class="px-4 py-2">{{ t('colService') }}</th>
                            <th class="px-4 py-2">{{ t('colStatus') }}</th>
                            <th class="px-4 py-2 text-right">
                                {{ t('colLatency') }}
                            </th>
                            <th class="px-4 py-2 text-right">
                                {{ t('colUptime') }}
                            </th>
                            <th class="px-4 py-2">{{ t('colUsage') }}</th>
                            <th class="px-4 py-2 text-right">
                                {{ t('colChecked') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="service in group.rows"
                            :key="service.key"
                            class="border-b border-border/40 align-top last:border-0"
                        >
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="h-2 w-2 shrink-0 rounded-full"
                                        :class="STATUS_CLASS[service.status]"
                                    />
                                    <span class="font-medium">{{
                                        service.label
                                    }}</span>
                                    <a
                                        v-if="service.url"
                                        :href="service.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-muted-foreground hover:text-foreground"
                                    >
                                        <ExternalLink class="h-3 w-3" />
                                    </a>
                                    <span
                                        v-if="service.critical"
                                        class="rounded border border-border px-1 text-[10px] tracking-wider text-muted-foreground uppercase"
                                    >
                                        {{ t('critical') }}
                                    </span>
                                </div>
                                <p
                                    v-if="service.note"
                                    class="mt-1 text-[11px] leading-snug text-muted-foreground"
                                >
                                    {{ service.note }}
                                </p>
                                <p
                                    v-if="detailLine(service)"
                                    class="mt-1 font-mono text-[10px] leading-snug break-all text-muted-foreground/70"
                                >
                                    {{ detailLine(service) }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="STATUS_TEXT[service.status]">
                                    {{ t(`status.${service.status}`) }}
                                </span>
                                <p
                                    v-if="service.reason"
                                    class="text-[11px] leading-snug text-muted-foreground"
                                >
                                    {{ t(`reason.${service.reason}`) }}
                                </p>
                                <!--
                                  How long it has been like this. An outage
                                  five minutes old and one five days old need
                                  different responses and otherwise look
                                  identical on a status board.
                                -->
                                <p
                                    v-if="service.incident"
                                    class="text-[11px] text-red-600 dark:text-red-400"
                                >
                                    {{
                                        duration(
                                            service.incident.duration_seconds,
                                        )
                                    }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                {{
                                    service.latency_ms === null
                                        ? '—'
                                        : `${service.latency_ms} ms`
                                }}
                            </td>
                            <td class="px-4 py-3 text-right font-mono text-xs">
                                {{
                                    service.probed
                                        ? pct(service.uptime_24h)
                                        : t('notProbed')
                                }}
                            </td>
                            <td class="px-4 py-3 text-xs text-muted-foreground">
                                {{ usageLabel(service) }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    service.checked_at === null
                                        ? t('noData')
                                        : ago(service.checked_at)
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <!-- One card per machine that reports. -->
        <Card v-for="host in hosts" :key="host.host ?? 'unknown'">
            <CardHeader>
                <CardTitle>
                    {{ t('hostTitle') }}
                    <span
                        class="ml-2 font-mono text-xs font-normal text-muted-foreground"
                    >
                        {{ host.host }}
                    </span>
                </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p
                    v-if="host.stale"
                    class="text-sm text-amber-600 dark:text-amber-400"
                >
                    {{ t('hostStale') }}
                </p>
                <div
                    class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6"
                >
                    <div>
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('hostLoad') }}
                        </p>
                        <p class="font-mono text-lg">
                            {{
                                host.metrics.load
                                    ? host.metrics.load[0].toFixed(2)
                                    : '—'
                            }}
                            <span class="text-xs text-muted-foreground">
                                / {{ host.metrics.cpus ?? '?' }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('hostMemory') }}
                        </p>
                        <p class="font-mono text-lg">
                            {{
                                freeMemory(host) === null
                                    ? '—'
                                    : `${freeMemory(host)}%`
                            }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('hostSwap') }}
                        </p>
                        <p class="font-mono text-lg">
                            {{
                                swapUsed(host) === null
                                    ? '—'
                                    : `${swapUsed(host)}%`
                            }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('hostDisk') }}
                        </p>
                        <p class="font-mono text-lg">
                            {{
                                host.metrics.disk
                                    ? `${host.metrics.disk.used_percent}%`
                                    : '—'
                            }}
                            <span
                                v-if="host.metrics.disk"
                                class="text-xs text-muted-foreground"
                            >
                                {{ host.metrics.disk.free_gb }}G free
                            </span>
                        </p>
                    </div>
                    <div>
                        <!--
                          A reboot is invisible in every other number here, and
                          this host has come back from one with two containers
                          set to restart=no and stayed half-down until somebody
                          noticed.
                        -->
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('hostUptime') }}
                        </p>
                        <p class="font-mono text-lg">
                            {{
                                host.metrics.uptime_seconds === null
                                    ? '—'
                                    : duration(host.metrics.uptime_seconds)
                            }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            {{ t('hostReported') }}
                        </p>
                        <p class="font-mono text-lg">
                            {{ ago(host.reported_at) }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="host.unregistered.length > 0"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('unregistered') }}
                    <span class="font-mono">{{
                        host.unregistered.join(', ')
                    }}</span>
                </p>
            </CardContent>
        </Card>

        <!--
          The answer to "what is nobody using". Only services this database can
          actually count appear; the rest are named as unmeasured on their own
          rows rather than being quietly condemned here.
        -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('idleTitle') }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
                <p class="text-xs text-muted-foreground">{{ t('idleHint') }}</p>
                <p
                    v-if="idle.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('idleEmpty') }}
                </p>
                <div v-else class="flex flex-wrap gap-2">
                    <div
                        v-for="service in idle"
                        :key="service.key"
                        class="rounded-lg border border-border/70 px-3 py-2"
                    >
                        <p class="text-sm font-medium">{{ service.label }}</p>
                        <p class="text-[11px] text-muted-foreground">
                            {{
                                service.usage?.idle_days === null ||
                                service.usage?.idle_days === undefined
                                    ? t('idleNever')
                                    : t('idleSince').replace(
                                          '{days}',
                                          String(service.usage.idle_days),
                                      )
                            }}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- What has actually gone wrong, newest first. -->
        <Card>
            <CardHeader>
                <CardTitle>{{ t('incidentsTitle') }}</CardTitle>
            </CardHeader>
            <CardContent class="p-0">
                <p
                    v-if="incidents.length === 0"
                    class="px-6 pb-6 text-sm text-muted-foreground"
                >
                    {{ t('incidentsEmpty') }}
                </p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="incident in incidents"
                            :key="incident.id"
                            class="border-b border-border/40 last:border-0"
                        >
                            <td class="px-4 py-2">
                                <span
                                    class="mr-2 inline-block h-2 w-2 rounded-full"
                                    :class="STATUS_CLASS[incident.status]"
                                />
                                {{ incident.label }}
                            </td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                {{
                                    incident.reason
                                        ? t(`reason.${incident.reason}`)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-4 py-2 text-right font-mono text-xs text-muted-foreground"
                            >
                                {{ duration(incident.duration_seconds) }}
                                <span
                                    v-if="incident.resolved_at === null"
                                    class="text-amber-500"
                                >
                                    · {{ t('incidentOngoing') }}
                                </span>
                            </td>
                            <td
                                class="px-4 py-2 text-right font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    incident.started_at
                                        ?.slice(0, 16)
                                        .replace('T', ' ') ?? '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <p class="text-xs text-muted-foreground">
            {{
                t('retention').replace(
                    '{days}',
                    String(settings.retention_days),
                )
            }}
            <span v-if="!settings.alerts"> · {{ t('alertsOff') }}</span>
        </p>
    </div>
</template>
