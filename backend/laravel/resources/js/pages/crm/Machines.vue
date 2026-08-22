<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AgeCell from '@/components/console/AgeCell.vue';
import DayStrip from '@/components/console/DayStrip.vue';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import {
    age,
    dateTime,
    num,
    plural,
    secondsSince,
    STATUS_TONE,
    toneColor,
} from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * "Машины" — forty-six programs as tiles rather than as a table.
 *
 * A table of forty-six rows is read line by line; a grid of forty-six tiles is
 * read at a glance, and the colour plus the day strip is the whole reading.
 * What is broken is lifted out of the grid into its own band at the top,
 * because looking for the red row is the one thing nobody should have to do.
 *
 * Health is never inferred from reachability where reachability lies: a PoA
 * node that stopped sealing still answers 200, a container in a crash loop
 * still reads as running. The sweep knows that; this page only draws what it
 * concluded, and never probes anything itself.
 */
type Usage = {
    measured: boolean;
    idle_days: number | null;
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
    status: string;
    reason: string | null;
    latency_ms: number | null;
    checked_at: string | null;
    uptime_24h: number | null;
    incident: {
        status: string;
        reason: string | null;
        started_at: string | null;
        duration_seconds: number;
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

const props = defineProps<{
    services: Service[];
    summary: {
        counts: Record<string, number>;
        total: number;
        critical_down: number;
        measured: number;
    };
    hosts: Host[];
    incidents: {
        id: number;
        label: string;
        status: string;
        reason: string | null;
        started_at: string | null;
        resolved_at: string | null;
        duration_seconds: number;
    }[];
    idle: Service[];
    strips: Record<string, string[]>;
}>();

const { locale, t, tag } = useLocale(consoleMessages);

const onlyProblems = ref(false);

const GROUPS = ['chain', 'web', 'infra', 'daemon', 'onchain', 'product'];

const broken = computed(() =>
    props.services
        .filter((service) => service.incident !== null)
        .sort(
            (a, b) =>
                (b.incident?.duration_seconds ?? 0) -
                (a.incident?.duration_seconds ?? 0),
        ),
);

const grouped = computed(() =>
    GROUPS.map((group) => {
        const services = props.services.filter(
            (service) =>
                service.group === group &&
                (!onlyProblems.value ||
                    ['down', 'degraded', 'unknown'].includes(service.status)),
        );

        const tone = services.some((service) => service.status === 'down')
            ? 'critical'
            : services.some((service) => service.status === 'degraded')
              ? 'warning'
              : services.some((service) => service.status === 'unknown')
                ? 'unknown'
                : 'calm';

        return { group, services, tone };
    }).filter((row) => row.services.length > 0),
);

/** Not measured is a third answer, and it is why the idle list is honest. */
const unmeasured = computed(
    () => props.services.filter((service) => !service.usage?.measured).length,
);

function usageLine(service: Service): string {
    if (!service.deployed) {
        return t('machines.off');
    }

    if (!service.usage?.measured) {
        return t('machines.notMeasured');
    }

    const count = service.usage.count_30d ?? 0;

    if (count === 0) {
        return service.usage.idle_days === null
            ? t('machines.idleNever')
            : t('machines.idleDays', { days: service.usage.idle_days });
    }

    return `${num(count)} ${service.usage.unit ?? ''}`.trim();
}

function ago(iso: string | null, seconds?: number | null): string {
    const value = age(seconds ?? secondsSince(iso));

    return value === null
        ? '—'
        : `${value.value} ${plural(locale.value, value.count, t(value.unit))}`;
}

function tone(status: string): string {
    return STATUS_TONE[status] ?? 'plain';
}
</script>

<template>
    <Head title="Мостик · Машины" />

    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap">
        <h1 class="mk-h1">{{ t('machines.title') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{
                t('machines.registry', {
                    total: summary.total,
                    down: summary.counts.down ?? 0,
                    degraded: summary.counts.degraded ?? 0,
                    unknown: summary.counts.unknown ?? 0,
                })
            }}
        </span>
        <div style="margin-left: auto; display: flex; gap: 8px">
            <button
                type="button"
                class="mk-btn"
                :class="{ 'mk-act': onlyProblems }"
                @click="onlyProblems = !onlyProblems"
            >
                {{ onlyProblems ? t('machines.all') : t('machines.onlyProblems') }}
            </button>
            <span class="mk-btn mk-ghost mk-wide">{{
                t('machines.registryNote')
            }}</span>
        </div>
    </div>

    <!-- What is broken, lifted out of the grid. -->
    <div v-if="broken.length">
        <Rule :label="t('machines.attention')" />
        <div style="margin-top: 10px">
            <div
                v-for="service in broken"
                :id="service.key"
                :key="service.key"
                class="mk-hair"
                style="
                    display: flex;
                    align-items: center;
                    gap: 18px;
                    padding: 13px 4px 13px 0;
                "
            >
                <AgeCell
                    :seconds="service.incident?.duration_seconds ?? null"
                    :tone="tone(service.status)"
                    compact
                />
                <span
                    class="mk-dot"
                    :style="{ background: toneColor(tone(service.status)) }"
                />
                <div style="flex: 1; min-width: 0">
                    <div style="font-size: 14px; font-weight: 600">
                        {{ service.label }} · {{ t(`status.${service.status}`) }}
                    </div>
                    <div
                        class="mk-t2"
                        style="margin-top: 3px; font-size: 12px"
                    >
                        {{ service.incident?.reason ?? service.reason ?? '—' }}
                        <template v-if="service.critical">
                            · {{ t('machines.critical') }}</template
                        >
                    </div>
                </div>
                <div class="mk-wide" style="width: 132px; flex: 0 0 132px">
                    <DayStrip :cells="strips[service.key] ?? []" />
                    <div class="mk-k" style="margin-top: 5px">
                        {{ t('evidence.day') }}
                    </div>
                </div>
                <a
                    v-if="service.url"
                    :href="service.url"
                    target="_blank"
                    rel="noreferrer"
                    class="mk-btn mk-act"
                    >{{ t('action.openMachine') }}</a
                >
                <!-- A service with no address of its own (a container, a
                     daemon, a table) has nothing to open, so the column says
                     when it was last looked at instead of holding a dead
                     button. -->
                <span
                    v-else
                    class="mk-m mk-t3"
                    style="width: 132px; text-align: right; font-size: 11.5px"
                    >{{ dateTime(service.checked_at, tag) }}</span
                >
            </div>
        </div>
    </div>

    <!-- The grid, one band per group. -->
    <div v-for="row in grouped" :key="row.group">
        <Rule
            :label="t(`group.${row.group}`)"
            :note="String(row.services.length)"
            :tone="row.tone === 'calm' ? 'calm' : row.tone"
        />
        <div class="mk-grid" style="margin-top: 11px">
            <div
                v-for="service in row.services"
                :id="service.key"
                :key="service.key"
                class="mk-card"
                :class="{
                    'mk-bad': service.status === 'down',
                    'mk-warn': service.status === 'degraded',
                }"
            >
                <div style="display: flex; align-items: center; gap: 7px">
                    <span
                        class="mk-dot"
                        :class="{ 'mk-hatch': service.status === 'unknown' }"
                        :style="
                            service.status === 'unknown'
                                ? { borderRadius: '999px' }
                                : {
                                      background:
                                          service.status === 'up'
                                              ? 'var(--mk-calm)'
                                              : service.status === 'off'
                                                ? 'var(--mk-flat)'
                                                : toneColor(tone(service.status)),
                                  }
                        "
                    />
                    <span
                        class="mk-clip"
                        style="font-size: 11.5px; font-weight: 600"
                        :title="service.note ?? service.label"
                        >{{ service.label }}</span
                    >
                    <span
                        class="mk-m mk-t3"
                        style="margin-left: auto; font-size: 10px"
                        >{{
                            service.latency_ms === null
                                ? '—'
                                : `${service.latency_ms} ms`
                        }}</span
                    >
                </div>
                <div style="margin-top: 9px">
                    <DayStrip
                        :cells="strips[service.key] ?? []"
                        :width="150"
                        :height="6"
                    />
                </div>
                <div
                    style="
                        margin-top: 7px;
                        display: flex;
                        align-items: center;
                        gap: 6px;
                    "
                >
                    <span
                        class="mk-t3 mk-clip"
                        style="font-size: 10px"
                        >{{ usageLine(service) }}</span
                    >
                    <span
                        v-if="service.critical"
                        class="mk-k"
                        style="color: var(--mk-fainter); margin-left: auto"
                        >{{ t('machines.critical') }}</span
                    >
                </div>
            </div>
        </div>
    </div>

    <div
        style="
            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 22px;
        "
    >
        <!-- The machines under all of it. Pushed, not pulled: Laravel is in a
             container and cannot see the docker daemon, the tmux sessions,
             the load or the disk. -->
        <div>
            <Rule :label="t('machines.hosts')" :note="t('machines.hostsNote')" />
            <div style="margin-top: 11px; display: flex; gap: 12px; flex-wrap: wrap">
                <div
                    v-for="host in hosts"
                    :key="host.host ?? 'unknown'"
                    class="mk-panel"
                    style="flex: 1; min-width: 260px; padding: 13px 15px"
                    :style="
                        host.stale
                            ? {
                                  borderColor: 'rgba(224,165,22,.35)',
                                  background: 'var(--mk-warning-soft)',
                              }
                            : {}
                    "
                >
                    <div style="display: flex; align-items: center; gap: 9px">
                        <span
                            class="mk-dot"
                            :class="{ 'mk-hatch': host.stale }"
                            :style="
                                host.stale
                                    ? { borderRadius: '999px' }
                                    : { background: 'var(--mk-calm)' }
                            "
                        />
                        <span
                            class="mk-m"
                            style="font-size: 13px; font-weight: 600"
                            >{{ host.host ?? '—' }}</span
                        >
                        <span
                            class="mk-m"
                            style="margin-left: auto; font-size: 11px"
                            :style="{
                                color: host.stale
                                    ? 'var(--mk-warning)'
                                    : 'var(--mk-faint)',
                            }"
                        >
                            {{
                                host.stale
                                    ? t('machines.silent', {
                                          ago: ago(null, host.age_seconds),
                                      })
                                    : t('machines.ago', {
                                          ago: ago(null, host.age_seconds),
                                      })
                            }}
                        </span>
                    </div>

                    <div
                        v-if="!host.stale"
                        style="
                            margin-top: 12px;
                            display: flex;
                            gap: 18px;
                            flex-wrap: wrap;
                        "
                    >
                        <div v-if="host.metrics.load">
                            <p class="mk-k" style="margin: 0">
                                {{ t('machines.load') }}
                            </p>
                            <p
                                class="mk-num"
                                style="margin: 5px 0 0; font-size: 17px"
                            >
                                {{ host.metrics.load[0]?.toFixed(2) }}
                            </p>
                            <p
                                class="mk-t3"
                                style="margin: 2px 0 0; font-size: 10px"
                            >
                                {{
                                    t('machines.ofCpus', {
                                        cpus: host.metrics.cpus ?? '—',
                                    })
                                }}
                            </p>
                        </div>
                        <div v-if="host.metrics.memory">
                            <p class="mk-k" style="margin: 0">
                                {{ t('machines.memory') }}
                            </p>
                            <p
                                class="mk-num"
                                style="margin: 5px 0 0; font-size: 17px"
                            >
                                {{
                                    Math.round(
                                        (host.metrics.memory.available_mb /
                                            host.metrics.memory.total_mb) *
                                            100,
                                    )
                                }}%
                            </p>
                            <p
                                class="mk-t3"
                                style="margin: 2px 0 0; font-size: 10px"
                            >
                                {{ t('machines.free') }}
                            </p>
                        </div>
                        <div v-if="host.metrics.disk">
                            <p class="mk-k" style="margin: 0">
                                {{ t('machines.disk') }}
                            </p>
                            <p
                                class="mk-num"
                                style="margin: 5px 0 0; font-size: 17px"
                                :style="{
                                    color:
                                        host.metrics.disk.used_percent > 85
                                            ? 'var(--mk-warning)'
                                            : 'var(--mk-text)',
                                }"
                            >
                                {{ host.metrics.disk.used_percent }}%
                            </p>
                            <p
                                class="mk-t3"
                                style="margin: 2px 0 0; font-size: 10px"
                            >
                                {{ host.metrics.disk.free_gb }} GB
                            </p>
                        </div>
                        <div v-if="host.metrics.uptime_seconds">
                            <p class="mk-k" style="margin: 0">
                                {{ t('machines.uptime') }}
                            </p>
                            <p
                                class="mk-num"
                                style="margin: 5px 0 0; font-size: 17px"
                            >
                                {{
                                    Math.floor(
                                        host.metrics.uptime_seconds / 86400,
                                    )
                                }}
                            </p>
                            <p
                                class="mk-t3"
                                style="margin: 2px 0 0; font-size: 10px"
                            >
                                {{ t('machines.noReboot') }}
                            </p>
                        </div>
                    </div>

                    <p
                        v-else
                        style="
                            margin: 10px 0 0;
                            font-size: 11.5px;
                            line-height: 1.55;
                            color: #b79445;
                        "
                    >
                        {{ t('machines.silentNote') }}
                    </p>

                    <p
                        v-if="host.unregistered.length"
                        class="mk-t3"
                        style="margin: 12px 0 0; font-size: 11px"
                    >
                        {{
                            t('machines.unregistered', {
                                list: host.unregistered.join(', '),
                            })
                        }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Nobody uses it. Only measurable services can land here — that is
             what keeps the RPC, the explorer and the DEX off a list that
             would otherwise recommend deleting half the product. -->
        <div>
            <Rule :label="t('machines.idle')" />
            <div
                style="
                    margin-top: 11px;
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                "
            >
                <div
                    v-for="service in idle.slice(0, 8)"
                    :key="service.key"
                    class="mk-panel"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        padding: 11px 13px;
                    "
                >
                    <span style="font-size: 12.5px; font-weight: 500">{{
                        service.label
                    }}</span>
                    <span
                        class="mk-m"
                        style="
                            margin-left: auto;
                            font-size: 11.5px;
                            color: var(--mk-warning);
                        "
                        >{{ usageLine(service) }}</span
                    >
                </div>
                <!-- Capped: this is a finding, not a directory. A list long
                     enough to scroll stops being read as one. -->
                <p
                    v-if="idle.length > 8"
                    class="mk-m mk-t3"
                    style="font-size: 11px"
                >
                    {{ t('machines.idleMore', { count: idle.length - 8 }) }}
                </p>
                <p
                    class="mk-t3"
                    style="margin: 4px 0 0; font-size: 11px; line-height: 1.55"
                >
                    {{ t('machines.idleNote', { count: unmeasured }) }}
                </p>
            </div>
        </div>
    </div>

    <!-- What broke, and for how long. -->
    <div>
        <Rule
            :label="t('machines.incidents')"
            :note="t('machines.incidentsNote')"
        />
        <div style="margin-top: 10px">
            <div
                v-for="incident in incidents"
                :key="incident.id"
                class="mk-hair"
                style="
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    padding: 9px 2px;
                "
            >
                <span
                    class="mk-dot"
                    :style="{ background: toneColor(tone(incident.status)) }"
                />
                <span
                    class="mk-clip"
                    style="width: 190px; flex: 0 0 190px; font-size: 12.5px"
                    >{{ incident.label }}</span
                >
                <span class="mk-t3 mk-clip" style="flex: 1; font-size: 11.5px">{{
                    incident.reason ?? '—'
                }}</span>
                <span
                    class="mk-num"
                    style="width: 130px; text-align: right; font-size: 12.5px"
                    :style="{
                        color: incident.resolved_at
                            ? 'var(--mk-dim)'
                            : 'var(--mk-warning)',
                    }"
                >
                    {{ ago(null, incident.duration_seconds) }}
                    <template v-if="!incident.resolved_at">
                        · {{ t('machines.ongoing') }}</template
                    >
                </span>
                <span
                    class="mk-m"
                    style="
                        width: 130px;
                        text-align: right;
                        font-size: 11.5px;
                        color: var(--mk-fainter);
                    "
                    >{{ dateTime(incident.started_at, tag) }}</span
                >
            </div>
            <p
                v-if="!incidents.length"
                class="mk-t3"
                style="font-size: 12px"
            >
                {{ t('machines.noIncidents') }}
            </p>
        </div>
    </div>
</template>
