<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AgeCell from '@/components/console/AgeCell.vue';
import DayStrip from '@/components/console/DayStrip.vue';
import Rule from '@/components/console/Rule.vue';
import Spark from '@/components/console/Spark.vue';
import { useConsoleLive } from '@/composables/useConsolePulse';
import { useLocale } from '@/composables/useLocale';
import {
    age,
    dateTime,
    grouped,
    num,
    percent,
    plural,
    shortTime,
    toneColor,
    usd,
} from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * "Сейчас" — the home of the console.
 *
 * One queue, ordered by urgency, in which an incident, an overdue promise, a
 * whale's first trade, a sagging retention curve and an emptying gas tank
 * stand in the same line. Every row has a duration on the left (that column
 * *is* the priority), one obvious next step on the right, and a way to put it
 * down until morning — because half of what a duty operator meets is not "do
 * this now" but "do not show me this until nine".
 */
type Evidence = {
    type: 'strip' | 'spark' | 'value' | 'text';
    label: string;
    cells?: string[];
    values?: number[];
    value?: number;
    unit?: string;
    text?: string;
    tone?: string;
};

type Item = {
    key: string;
    kind: string;
    severity: string;
    title: string;
    body: string;
    params: Record<string, string | number>;
    since: string | null;
    duration_seconds: number | null;
    evidence: Evidence | null;
    action: { key: string; href: string } | null;
    snoozable: boolean;
    snoozed_until: string | null;
};

type Watch = {
    key: string;
    severity: string;
    title: string;
    body: string;
    params: Record<string, string | number>;
    value: string | number | null;
    value_kind: string;
    count?: number;
    href: string | null;
    items?: Item[];
};

type Tile = {
    key: string;
    value: number | null;
    of?: number;
    unit: string;
    tone: string;
    note: string;
    params: Record<string, string | number>;
    spark: number[] | null;
};

const props = defineProps<{
    attention: Item[];
    watch: Watch[];
    quiet: {
        is_quiet: boolean;
        since: string | null;
        last_sweep: string | null;
        answered: number;
        registered: number;
    };
    tiles: Tile[];
}>();

const page = usePage();
const { locale, t, tag } = useLocale(consoleMessages);

const operator = computed(
    () =>
        (page.props.auth as { user?: { name?: string } } | undefined)?.user
            ?.name ?? '—',
);

/*
 * The clock this lens does its arithmetic against.
 *
 * A ref rather than a constant, because the queue now re-reads itself while
 * nobody navigates: a page that prints "quiet for 20 minutes" off the moment
 * it was opened would freeze that number for the rest of the night.
 */
const now = ref(new Date());

/*
 * The queue on every desk at once.
 *
 * It is drawn from six sources and cached, so what is watched here is the
 * material underneath it — an incident opening, a sweep landing, somebody
 * putting a row to sleep. The sweep alone lands every five minutes, which is
 * the floor on how stale an open queue can get and matches the rate the
 * material is collected at.
 */
useConsoleLive('now', () => {
    now.value = new Date();

    router.reload({ only: ['attention', 'watch', 'quiet', 'tiles', 'console'] });
});

const quietFor = computed(() => {
    if (!props.quiet.since) {
        return null;
    }

    const seconds = Math.floor(
        (now.value.getTime() - Date.parse(props.quiet.since)) / 1000,
    );

    return age(seconds);
});

const sweptAgo = computed(() => {
    if (!props.quiet.last_sweep) {
        return t('unit.never');
    }

    const seconds = Math.floor(
        (now.value.getTime() - Date.parse(props.quiet.last_sweep)) / 1000,
    );
    const value = age(seconds);

    return value === null
        ? t('unit.never')
        : `${value.value} ${plural(locale.value, value.count, t(value.unit))}`;
});

function snooze(item: Item) {
    router.post('/crm/snooze', { key: item.key }, { preserveScroll: true });
}

function wake(item: Item) {
    router.delete('/crm/snooze', {
        data: { key: item.key },
        preserveScroll: true,
    });
}

function tileValue(tile: Tile): string {
    if (tile.value === null) {
        return '—';
    }

    if (tile.unit === 'usd') {
        return usd(tile.value);
    }

    if (tile.unit === 'fraction') {
        return `${tile.value}/${tile.of}`;
    }

    return num(tile.value);
}
</script>

<template>
    <Head title="Пульт · Сейчас" />

    <div style="display: flex; align-items: baseline; gap: 12px">
        <h1 class="mk-h1">{{ t('nav.now') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{ dateTime(now.toISOString(), tag) }} ·
            {{ t('top.shift', { name: operator }) }}
        </span>
    </div>

    <!-- Requires action. -->
    <div v-if="attention.length">
        <Rule :label="t('feed.attention')" :note="String(attention.length)" />
        <div style="margin-top: 10px">
            <div
                v-for="item in attention"
                :key="item.key"
                class="mk-feed-row mk-hair"
            >
                <AgeCell :seconds="item.duration_seconds" :tone="item.severity" />

                <div style="flex: 1; min-width: 0">
                    <div style="display: flex; align-items: center; gap: 9px">
                        <span
                            class="mk-dot"
                            :style="{ background: toneColor(item.severity) }"
                        />
                        <span
                            style="
                                font-size: 15px;
                                font-weight: 600;
                                letter-spacing: -0.01em;
                            "
                            >{{ t(item.title, grouped(item.params)) }}</span
                        >
                    </div>
                    <p
                        class="mk-t2"
                        style="
                            margin: 5px 0 0 16px;
                            font-size: 12.5px;
                            line-height: 1.5;
                        "
                    >
                        {{ t(item.body, grouped(item.params)) }}
                    </p>
                </div>

                <!-- The evidence: why this row is here, in the smallest form
                     that can be checked at a glance. -->
                <div
                    v-if="item.evidence"
                    class="mk-wide"
                    style="width: 150px; flex: 0 0 150px; padding-top: 2px"
                >
                    <DayStrip
                        v-if="item.evidence.type === 'strip'"
                        :cells="item.evidence.cells ?? []"
                    />
                    <Spark
                        v-else-if="item.evidence.type === 'spark'"
                        :values="item.evidence.values ?? []"
                        :tone="item.evidence.tone ?? 'plain'"
                        fill
                    />
                    <div
                        v-else-if="item.evidence.type === 'value'"
                        class="mk-num"
                        :style="{
                            fontSize: '17px',
                            color: toneColor(item.evidence.tone ?? 'plain'),
                        }"
                    >
                        {{
                            item.evidence.unit === 'usd'
                                ? usd(item.evidence.value ?? null)
                                : num(item.evidence.value ?? null)
                        }}
                        <span
                            v-if="item.evidence.unit === 'cyber'"
                            style="font-size: 11px"
                            >CYBER</span
                        >
                    </div>
                    <div
                        v-else
                        class="mk-m mk-t2"
                        style="font-size: 12px"
                    >
                        {{ item.evidence.text }}
                    </div>
                    <div class="mk-k" style="margin-top: 5px">
                        {{ t(item.evidence.label) }}
                    </div>
                </div>

                <div
                    class="mk-feed-actions"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding-top: 1px;
                    "
                >
                    <Link
                        v-if="item.action"
                        :href="item.action.href"
                        class="mk-btn mk-act"
                        >{{ t(`action.${item.action.key}`) }}</Link
                    >
                    <button
                        v-if="item.snoozable"
                        type="button"
                        class="mk-btn mk-ghost"
                        @click="snooze(item)"
                    >
                        {{ t('action.snooze') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Silence, drawn as a state. Without the last sweep and the count of
         services that answered it, an empty screen and a broken collector
         look exactly the same. -->
    <div
        v-else
        style="
            display: flex;
            align-items: center;
            gap: 26px;
            padding: 34px 30px;
            border: 1px solid var(--mk-hair);
            background: var(--mk-panel);
        "
    >
        <svg
            width="54"
            height="54"
            viewBox="0 0 24 24"
            fill="none"
            stroke="var(--mk-calm)"
            stroke-width="1.2"
            stroke-linecap="round"
        >
            <path d="M2.5 12h19" />
        </svg>
        <div>
            <p
                style="
                    margin: 0;
                    font-size: 26px;
                    font-weight: 700;
                    letter-spacing: -0.02em;
                "
            >
                {{ t('quiet.title') }}
            </p>
            <p
                class="mk-t2"
                style="
                    margin: 7px 0 0;
                    font-size: 13.5px;
                    line-height: 1.6;
                    max-width: 620px;
                "
            >
                {{
                    t('quiet.body', {
                        ago: sweptAgo,
                        answered: quiet.answered,
                        registered: quiet.registered,
                    })
                }}
            </p>
        </div>
        <div style="margin-left: auto; text-align: right">
            <p class="mk-k" style="margin: 0">{{ t('quiet.duration') }}</p>
            <p
                v-if="quietFor"
                class="mk-num"
                style="margin: 6px 0 0; font-size: 30px; color: var(--mk-calm)"
            >
                {{ quietFor.value }}
            </p>
            <p v-else class="mk-t3" style="margin: 6px 0 0; font-size: 12px">
                {{ t('quiet.sinceUnknown') }}
            </p>
        </div>
    </div>

    <!-- Watching: nothing to do, but nothing hidden either. -->
    <div v-if="watch.length">
        <Rule :label="t('feed.watch')" :note="t('feed.watchNote')" />
        <div style="margin-top: 10px">
            <div
                v-for="row in watch"
                :key="row.key"
                class="mk-hair"
                style="
                    display: flex;
                    align-items: center;
                    gap: 11px;
                    padding: 11px 4px 11px 0;
                "
            >
                <span
                    class="mk-dot"
                    :class="{ 'mk-hatch': row.severity === 'unknown' }"
                    :style="
                        row.severity === 'unknown'
                            ? { borderRadius: '999px' }
                            : { background: toneColor(row.severity) }
                    "
                />
                <span style="font-size: 13px; font-weight: 500">{{
                    t(row.title, grouped(row.params))
                }}</span>
                <span class="mk-t3 mk-wide" style="font-size: 12.5px">{{
                    t(row.body, grouped(row.params))
                }}</span>
                <span style="margin-left: auto; display: flex; gap: 10px">
                    <span
                        v-if="row.value_kind === 'time'"
                        class="mk-m mk-t3"
                        style="font-size: 12px"
                        >{{
                            t('action.snoozedUntil', {
                                time: shortTime(String(row.value), tag),
                            })
                        }}</span
                    >
                    <span
                        v-else-if="row.value_kind === 'percent'"
                        class="mk-num"
                        style="font-size: 13px; color: var(--mk-accent)"
                        >{{ percent(Number(row.value)) }}</span
                    >
                    <span
                        v-else-if="row.value_kind === 'count'"
                        class="mk-num"
                        style="font-size: 13px"
                        >{{ num(row.count ?? null) }}</span
                    >
                </span>
                <template v-if="row.items">
                    <button
                        v-for="item in row.items"
                        :key="item.key"
                        type="button"
                        class="mk-btn mk-ghost"
                        @click="wake(item)"
                    >
                        {{ t('action.wake') }}
                    </button>
                </template>
                <Link
                    v-else-if="row.href"
                    :href="row.href"
                    class="mk-btn mk-ghost"
                    >→</Link
                >
            </div>
        </div>
    </div>

    <!-- Thirty days of background. -->
    <div style="margin-top: auto">
        <Rule :label="t('feed.background')" :note="t('feed.backgroundNote')" />
        <div class="mk-tile-row" style="margin-top: 12px">
            <div v-for="tile in tiles" :key="tile.key" class="mk-tile">
                <p class="mk-k" style="margin: 0">{{ t(`tiles.${tile.key}`) }}</p>
                <p
                    class="mk-num"
                    style="margin: 7px 0 0; font-size: 26px"
                    :style="{
                        color:
                            tile.tone === 'accent'
                                ? 'var(--mk-accent)'
                                : tile.tone === 'critical'
                                  ? 'var(--mk-critical)'
                                  : 'var(--mk-text)',
                    }"
                >
                    {{ tileValue(tile) }}
                </p>
                <p class="mk-t3" style="margin: 3px 0 0; font-size: 11px">
                    {{ t(tile.note, grouped(tile.params)) }}
                </p>
                <div v-if="tile.spark" style="margin-top: 8px">
                    <Spark :values="tile.spark" tone="plain" />
                </div>
            </div>
        </div>
    </div>
</template>
