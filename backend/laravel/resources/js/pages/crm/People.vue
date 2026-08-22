<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Rule from '@/components/console/Rule.vue';
import Spark from '@/components/console/Spark.vue';
import { useLocale } from '@/composables/useLocale';
import { age, num, plural, secondsSince, toneColor, usd } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * "Люди" — contacts read as what happened to them.
 *
 * The filter bar is gone. A filter is a question re-asked by hand every time;
 * a segment is that question saved with its rule visible, and the rule is on
 * the row so an empty segment can be told from a broken definition. The middle
 * of each row is the person's freshest signal rather than their database
 * columns, because that is what decides whether anybody writes to them today.
 */
type Signal = {
    key: string;
    at: string | null;
    params: Record<string, string | number>;
    tone: string;
};

type Row = {
    id: number;
    name: string;
    handle: string | null;
    type: string;
    status: string;
    usd: number | null;
    signal: Signal;
    spark: number[];
    action: string;
};

const props = defineProps<{
    segment: string;
    segments: { key: string; count: number; tone: string }[];
    rows: Row[];
    total: number;
    shown: number;
    limit: number;
    more: boolean;
    search: string | null;
}>();

const { locale, t } = useLocale(consoleMessages);

const query = ref(props.search ?? '');

const silenceDays = 30;

function rule(key: string): string {
    return t(`rule.${key}`, { days: silenceDays });
}

function go(segment: string) {
    router.get(
        '/crm/people',
        { segment, q: query.value || undefined },
        { preserveState: true, preserveScroll: true },
    );
}

/**
 * A signal, with its value words translated too.
 *
 * The server sends `source: "whale_bot"` because that is what the column
 * holds; a sentence that reads "appeared, from whale_bot" is a sentence
 * written by a database, so the value passes through the dictionary on its
 * way into the phrase.
 */
function signalText(signal: Signal): string {
    const params = { ...signal.params };

    if (typeof params.source === 'string') {
        params.source = t(`crm.source.${params.source}`);
    }

    if (typeof params.was === 'string') {
        params.was = t(params.was, signal.params);
    }

    return t(signal.key, params);
}

function more40() {
    router.get(
        '/crm/people',
        {
            segment: props.segment,
            q: query.value || undefined,
            rows: props.limit + 40,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function open(row: Row) {
    router.visit(`/crm/${row.id}`);
}

function ago(iso: string | null): string {
    const seconds = secondsSince(iso);
    const value = age(seconds);

    return value === null
        ? ''
        : t('signal.ago', {
              ago: `${value.value} ${plural(locale.value, value.count, t(value.unit))}`,
          });
}

const currentSegment = computed(
    () => props.segments.find((segment) => segment.key === props.segment) ?? null,
);
</script>

<template>
    <Head title="Пульт · Люди" />

    <div
        style="
            display: flex;
            gap: 24px;
            margin: -22px -24px;
            min-height: 0;
            flex: 1;
        "
    >
        <!-- Saved questions, with their rules. -->
        <aside
            style="
                width: 258px;
                flex: 0 0 258px;
                border-right: 1px solid var(--mk-hair);
                padding: 22px 18px;
                overflow-y: auto;
            "
            class="mk-wide"
        >
            <Rule :label="t('people.segments')" />
            <div
                style="
                    margin-top: 12px;
                    display: flex;
                    flex-direction: column;
                    gap: 1px;
                "
            >
                <button
                    v-for="segment in segments"
                    :key="segment.key"
                    type="button"
                    :title="rule(segment.key)"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        height: 34px;
                        padding: 0 11px;
                        font-size: 13px;
                        background: none;
                        border: 0;
                        cursor: pointer;
                        font-family: inherit;
                        text-align: left;
                    "
                    :style="
                        segment.key === props.segment
                            ? {
                                  background: 'var(--mk-accent-soft)',
                                  color: 'var(--mk-text)',
                                  boxShadow: 'inset 2px 0 0 var(--mk-accent)',
                              }
                            : { color: 'var(--mk-dim)' }
                    "
                    @click="go(segment.key)"
                >
                    <span
                        class="mk-dot"
                        :style="{ background: toneColor(segment.tone) }"
                    />
                    {{ t(`segment.${segment.key}`) }}
                    <span
                        class="mk-m mk-t3"
                        style="margin-left: auto; font-size: 11px"
                        >{{ num(segment.count) }}</span
                    >
                </button>
            </div>
            <p
                class="mk-t3"
                style="margin: 16px 11px 0; font-size: 11px; line-height: 1.55"
            >
                {{ t('people.segmentNote') }}
            </p>
        </aside>

        <div
            style="
                flex: 1;
                min-width: 0;
                padding: 22px 24px 22px 0;
                display: flex;
                flex-direction: column;
                gap: 16px;
                overflow-y: auto;
            "
        >
            <div
                style="
                    display: flex;
                    align-items: baseline;
                    gap: 12px;
                    flex-wrap: wrap;
                "
            >
                <h1 class="mk-h1">{{ t(`segment.${segment}`) }}</h1>
                <span class="mk-m mk-t3" style="font-size: 12px">
                    {{ num(currentSegment?.count ?? total) }} ·
                    {{ rule(segment) }}
                </span>
                <div style="margin-left: auto; display: flex; gap: 8px">
                    <input
                        v-model="query"
                        class="mk-input"
                        :placeholder="t('people.search')"
                        @keyup.enter="go(segment)"
                    />
                    <Link
                        href="/crm/sync"
                        method="post"
                        as="button"
                        class="mk-btn mk-ghost"
                        >{{ t('people.sync') }}</Link
                    >
                    <a href="/crm/export" class="mk-btn">{{
                        t('people.export')
                    }}</a>
                </div>
            </div>

            <Rule
                :label="t('people.happening')"
                :note="t('people.sortNote')"
            />

            <div v-if="rows.length" style="flex: 1; min-height: 0">
                <!-- The row opens the dossier; the button is the one action
                     that is not "read more about them". A row that is itself a
                     link cannot contain another one, so the click is handled
                     rather than nested. -->
                <div
                    v-for="row in rows"
                    :key="row.id"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 18px;
                        padding: 13px 4px 13px 0;
                        cursor: pointer;
                    "
                    @click="open(row)"
                >
                    <span
                        class="mk-dot"
                        :style="{ background: toneColor(row.signal.tone) }"
                    />
                    <div style="width: 190px; flex: 0 0 190px; min-width: 0">
                        <div
                            class="mk-clip"
                            style="font-size: 13.5px; font-weight: 600"
                        >
                            {{ row.name }}
                        </div>
                        <div
                            class="mk-m mk-t3 mk-clip"
                            style="margin-top: 2px; font-size: 11px"
                        >
                            {{ row.handle ?? t(`crm.type.${row.type}`) }}
                        </div>
                    </div>
                    <div style="flex: 1; min-width: 0">
                        <div
                            class="mk-clip"
                            style="font-size: 13px; color: var(--mk-body)"
                        >
                            {{ signalText(row.signal) }}
                        </div>
                        <div
                            class="mk-t3"
                            style="margin-top: 2px; font-size: 11.5px"
                        >
                            {{ ago(row.signal.at) }}
                        </div>
                    </div>
                    <div class="mk-wide" style="width: 132px; flex: 0 0 132px">
                        <Spark :values="row.spark" :tone="row.signal.tone" />
                    </div>
                    <div style="width: 110px; flex: 0 0 110px; text-align: right">
                        <div class="mk-num" style="font-size: 14px">
                            {{ usd(row.usd) }}
                        </div>
                        <div class="mk-k" style="margin-top: 3px">
                            {{ t(`crm.status.${row.status}`) }}
                        </div>
                    </div>
                    <a
                        v-if="row.action === 'write' && row.handle"
                        :href="`https://t.me/${row.handle.replace('@', '')}`"
                        target="_blank"
                        rel="noreferrer"
                        class="mk-btn mk-act"
                        style="width: 108px"
                        @click.stop
                        >{{ t('person.write') }}</a
                    >
                    <span v-else class="mk-btn" style="width: 108px">{{
                        t('action.openPerson')
                    }}</span>
                </div>
            </div>
            <p v-else class="mk-t3" style="font-size: 13px">
                {{ t('people.empty') }}
            </p>

            <div style="display: flex; align-items: center; gap: 12px">
                <span class="mk-m mk-t3" style="font-size: 11px">
                    {{ t('people.shown', { shown, total }) }} ·
                    {{ t('people.rest') }}
                </span>
                <button
                    v-if="more"
                    type="button"
                    class="mk-btn mk-ghost"
                    style="margin-left: auto"
                    @click="more40"
                >
                    {{ t('people.more') }}
                </button>
            </div>
        </div>
    </div>
</template>
