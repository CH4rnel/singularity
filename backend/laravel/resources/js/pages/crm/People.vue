<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
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
    /* Where a message to this person would go, or null when nowhere does. */
    write: string | null;
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
    options: { types: string[]; statuses: string[] };
}>();

const { locale, t } = useLocale(consoleMessages);

const query = ref(props.search ?? '');

/*
 * Putting somebody on the books.
 *
 * The panel stays open after each save and the page comes back to the lens
 * rather than to the new dossier, because people are found in handfuls —
 * fifteen accounts in one afternoon — and a form that closes itself after
 * every one of them is fourteen extra decisions. The first field takes the
 * focus back so the next person is typed, not clicked at.
 */
const adding = ref(false);
const nameField = ref<HTMLInputElement | null>(null);

const draft = useForm({
    name: '',
    telegram: '',
    x_handle: '',
    email: '',
    evm_address: '',
    solana_address: '',
    tags: '',
    type: 'lead',
    status: 'new',
});

/*
 * Every field is optional in the column and in the rules, which together
 * would happily store a row that names nobody. A record with nothing to
 * identify it cannot be searched, written to or recognised later, so the
 * one thing asked for is any one of the ways of naming a person.
 */
const named = computed(() =>
    [
        draft.name,
        draft.telegram,
        draft.x_handle,
        draft.email,
        draft.evm_address,
        draft.solana_address,
    ].some((value) => value.trim() !== ''),
);

function openAdd() {
    adding.value = !adding.value;

    if (adding.value) {
        void nextTick(() => nameField.value?.focus());
    }
}

function create() {
    if (!named.value || draft.processing) {
        return;
    }

    draft
        .transform((data) => ({
            ...data,
            tags: data.tags
                .split(',')
                .map((tag) => tag.trim())
                .filter((tag) => tag !== ''),
        }))
        .post('/crm/people', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const type = draft.type;
                const status = draft.status;

                draft.reset();
                // The next fifteen are usually the same kind of person.
                draft.type = type;
                draft.status = status;

                void nextTick(() => nameField.value?.focus());
            },
        });
}

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
    () =>
        props.segments.find((segment) => segment.key === props.segment) ?? null,
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
                    <button
                        type="button"
                        class="mk-btn"
                        :class="adding ? 'mk-ghost' : 'mk-act'"
                        @click="openAdd"
                    >
                        {{ adding ? t('people.addClose') : t('people.add') }}
                    </button>
                </div>
            </div>

            <!-- The way somebody gets onto the books by hand. Sync writes the
                 people the chain and the bot already know about; this is for
                 the ones found by looking, who exist nowhere in our data until
                 they are typed. -->
            <form
                v-if="adding"
                class="mk-panel"
                style="padding: 14px 16px"
                @submit.prevent="create"
            >
                <Rule :label="t('people.addTitle')" />
                <p
                    class="mk-t3"
                    style="margin: 9px 0 0; font-size: 11.5px; line-height: 1.5"
                >
                    {{ t('people.addNote') }}
                </p>

                <div
                    style="
                        margin-top: 12px;
                        display: grid;
                        grid-template-columns: repeat(
                            auto-fit,
                            minmax(196px, 1fr)
                        );
                        gap: 8px;
                    "
                >
                    <input
                        ref="nameField"
                        v-model="draft.name"
                        class="mk-input"
                        :placeholder="t('person.namePlaceholder')"
                    />
                    <input
                        v-model="draft.telegram"
                        class="mk-input"
                        :placeholder="`${t('person.telegram')} · ${t('person.handlePlaceholder')}`"
                    />
                    <input
                        v-model="draft.x_handle"
                        class="mk-input"
                        :placeholder="`X · ${t('person.handlePlaceholder')}`"
                    />
                    <input
                        v-model="draft.email"
                        class="mk-input"
                        :placeholder="t('person.email')"
                    />
                    <input
                        v-model="draft.evm_address"
                        class="mk-input"
                        placeholder="EVM · 0x…"
                    />
                    <input
                        v-model="draft.solana_address"
                        class="mk-input"
                        placeholder="Solana"
                    />
                    <input
                        v-model="draft.tags"
                        class="mk-input"
                        :placeholder="`${t('person.tags')} · ${t('person.tagsPlaceholder')}`"
                    />
                </div>

                <div
                    style="
                        margin-top: 12px;
                        display: flex;
                        align-items: center;
                        gap: 16px;
                        flex-wrap: wrap;
                    "
                >
                    <div style="display: flex; align-items: center; gap: 6px">
                        <span class="mk-k">{{ t('person.editType') }}</span>
                        <button
                            v-for="value in options.types"
                            :key="value"
                            type="button"
                            class="mk-pick"
                            :class="{ 'mk-on': draft.type === value }"
                            @click="draft.type = value"
                        >
                            {{ t(`crm.type.${value}`) }}
                        </button>
                    </div>
                    <div style="display: flex; align-items: center; gap: 6px">
                        <span class="mk-k">{{ t('person.editStatus') }}</span>
                        <button
                            v-for="value in options.statuses"
                            :key="value"
                            type="button"
                            class="mk-pick"
                            :class="{ 'mk-on': draft.status === value }"
                            @click="draft.status = value"
                        >
                            {{ t(`crm.status.${value}`) }}
                        </button>
                    </div>
                    <button
                        type="submit"
                        class="mk-btn mk-act"
                        style="margin-left: auto"
                        :disabled="!named || draft.processing"
                    >
                        {{ t('people.add') }}
                    </button>
                </div>

                <!-- What the server refused, in red. The one thing this form
                     refuses on its own — a record naming nobody — is not an
                     error until somebody tries to save one, and an untouched
                     form that opens red is a form that shouts first. -->
                <p
                    v-if="Object.keys(draft.errors).length"
                    style="
                        margin: 10px 0 0;
                        font-size: 11.5px;
                        line-height: 1.5;
                        color: var(--mk-critical);
                    "
                >
                    {{ Object.values(draft.errors).join(' · ') }}
                </p>
                <p
                    v-else-if="!named"
                    class="mk-t3"
                    style="
                        margin: 10px 0 0;
                        font-size: 11.5px;
                        line-height: 1.5;
                    "
                >
                    {{ t('people.addNothing') }}
                </p>
            </form>

            <Rule :label="t('people.happening')" :note="t('people.sortNote')" />

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
                    <div
                        style="width: 110px; flex: 0 0 110px; text-align: right"
                    >
                        <div class="mk-num" style="font-size: 14px">
                            {{ usd(row.usd) }}
                        </div>
                        <div class="mk-k" style="margin-top: 3px">
                            {{ t(`crm.status.${row.status}`) }}
                        </div>
                    </div>
                    <a
                        v-if="row.write"
                        :href="row.write"
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
