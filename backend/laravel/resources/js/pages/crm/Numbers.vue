<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { num, percent, usd } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * "Числа" — six questions, each with an answer, its evidence and one line of
 * what to do about it.
 *
 * Ten tiles and eight tables became this. A tile is a number without a
 * question, and a number without a question gets read as whatever the reader
 * already believed; a question with a conclusion under it can be argued with,
 * which is the entire point of writing it down.
 *
 * The subject is a switch rather than two pages: an installation of the wallet
 * and a browser reading the site are different things, and the page says which
 * one it is counting at all times.
 */
type Question = {
    key: string;
    answer: {
        value: number | string | null;
        unit: string;
        tone: string;
        before?: number | null;
    };
    conclusion: { key: string; params: Record<string, string | number> };
    evidence: Record<string, unknown>;
};

const props = defineProps<{
    subject: string;
    subjects: string[];
    filters: { days: number; from: string; to: string };
    questions: Question[];
}>();

const { t } = useLocale(consoleMessages);

const WINDOWS = [7, 30, 90];

function setSubject(subject: string) {
    router.get(
        '/crm/numbers',
        { subject, days: props.filters.days },
        { preserveState: true, preserveScroll: true },
    );
}

function setWindow(days: number) {
    router.get(
        '/crm/numbers',
        { subject: props.subject, days },
        { preserveState: true, preserveScroll: true },
    );
}

function answer(question: Question): string {
    const { value, unit } = question.answer;

    if (value === null) {
        return '—';
    }

    if (unit === 'percent') {
        return String(value);
    }

    if (unit === 'usd') {
        return usd(Number(value));
    }

    if (unit === 'text') {
        return String(value);
    }

    return num(Number(value));
}

function suffix(question: Question): string {
    if (question.key === 'growth') {
        return props.subject === 'sessions'
            ? t('numbers.growth.suffixSessions')
            : t('numbers.growth.suffix');
    }

    if (question.key === 'return') {
        return t('numbers.return.suffix', {
            before: question.answer.before ?? '—',
        });
    }

    return t(`numbers.${question.key}.suffix`);
}

/** Bars: total behind, the confirmed part in front. */
function bars(question: Question) {
    const rows = (question.evidence.rows ?? []) as {
        day: string;
        total: number;
        part: number;
    }[];
    const max = Math.max(1, ...rows.map((row) => row.total));

    return rows.map((row) => ({
        ...row,
        totalHeight: Math.round((row.total / max) * 112),
        partHeight: Math.round((row.part / max) * 112),
    }));
}

/**
 * The evidence rows, typed per block.
 *
 * Separate helpers rather than one generic: a template expression cannot
 * carry type arguments, and the alternative is casting inside the markup.
 */
function cohortRows(question: Question) {
    return (question.evidence.rows ?? []) as {
        week: string;
        size: number;
        rates: Record<string, number | null>;
    }[];
}

function sourceRows(question: Question) {
    return (question.evidence.rows ?? []) as {
        source: string;
        campaign: string;
        users: number;
        activation_rate: number | null;
        d7: number | null;
    }[];
}

function errorRows(question: Question) {
    return (question.evidence.rows ?? []) as {
        event: string;
        error_code: string;
        total: number;
        users: number;
    }[];
}

function statusRows(question: Question) {
    return (question.evidence.rows ?? []) as {
        status: string;
        total: number;
    }[];
}

function tileRows(question: Question) {
    return (question.evidence.rows ?? []) as {
        key: string;
        value: number | null;
        unit: string;
        note: string;
        params: Record<string, string | number>;
    }[];
}

function legend(question: Question) {
    return (question.evidence.legend ?? {}) as {
        total: string;
        part: string | null;
    };
}

function steps(question: Question) {
    return (question.evidence.steps ?? []) as {
        key: string;
        value: number;
        of_top: number | null;
        of_previous: number | null;
    }[];
}

const cohortBuckets = ['d1', 'd7', 'd30'];

const answerTone = computed(
    () => (question: Question) =>
        question.answer.tone === 'critical'
            ? 'var(--mk-critical)'
            : question.answer.tone === 'accent'
              ? 'var(--mk-accent)'
              : 'var(--mk-text)',
);
</script>

<template>
    <Head title="Пульт · Числа" />

    <div
        style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap"
    >
        <h1 class="mk-h1">{{ t('numbers.title') }}</h1>
        <div style="display: flex; border: 1px solid var(--mk-hair-strong)">
            <button
                v-for="option in subjects"
                :key="option"
                type="button"
                style="
                    height: 28px;
                    display: flex;
                    align-items: center;
                    padding: 0 13px;
                    font-size: 12px;
                    border: 0;
                    cursor: pointer;
                    font-family: inherit;
                "
                :style="
                    option === subject
                        ? {
                              background: 'var(--mk-accent)',
                              color: 'var(--mk-bg)',
                              fontWeight: 600,
                          }
                        : { background: 'transparent', color: 'var(--mk-dim)' }
                "
                @click="setSubject(option)"
            >
                {{ t(`subject.${option}`) }}
            </button>
        </div>
        <span
            class="mk-t3 mk-wide"
            style="font-size: 11.5px; max-width: 320px; line-height: 1.4"
            >{{ t('numbers.subjectNote') }}</span
        >
        <div
            style="
                margin-left: auto;
                display: flex;
                align-items: center;
                gap: 8px;
            "
        >
            <div style="display: flex; border: 1px solid var(--mk-hair-strong)">
                <button
                    v-for="days in WINDOWS"
                    :key="days"
                    type="button"
                    style="
                        height: 28px;
                        padding: 0 11px;
                        font-size: 12px;
                        border: 0;
                        background: transparent;
                        cursor: pointer;
                        font-family: inherit;
                    "
                    :style="
                        days === filters.days
                            ? {
                                  background: 'rgba(232,236,236,.08)',
                                  color: 'var(--mk-text)',
                                  fontWeight: 600,
                              }
                            : { color: 'var(--mk-faint)' }
                    "
                    @click="setWindow(days)"
                >
                    {{ days }}
                </button>
            </div>
        </div>
    </div>

    <div
        v-for="question in questions"
        :id="question.key"
        :key="question.key"
        class="mk-hair"
        style="display: flex; gap: 28px; padding: 18px 0; flex-wrap: wrap"
    >
        <!-- The question, its answer and the one line that says what to do. -->
        <div style="width: 330px; flex: 0 0 330px">
            <p
                style="
                    margin: 0;
                    font-size: 15px;
                    font-weight: 600;
                    letter-spacing: -0.01em;
                "
            >
                {{ t(`numbers.${question.key}.title`) }}
            </p>
            <p
                class="mk-num"
                style="margin: 10px 0 0; font-size: 38px; line-height: 1"
                :style="{ color: answerTone(question) }"
            >
                {{ answer(question) }}<span
                    v-if="question.answer.value !== null"
                    class="mk-t3"
                    style="font-size: 14px; font-weight: 500"
                    >&nbsp;{{ suffix(question) }}</span
                >
            </p>
            <p
                class="mk-t2"
                style="margin: 11px 0 0; font-size: 12.5px; line-height: 1.55"
            >
                {{ t(question.conclusion.key, question.conclusion.params) }}
            </p>
        </div>

        <div style="flex: 1; min-width: 260px">
            <!-- Daily bars: how many opened it, and how many did something
                 the chain confirmed. -->
            <template v-if="question.evidence.type === 'bars'">
                <div
                    style="
                        display: flex;
                        align-items: flex-end;
                        gap: 3px;
                        height: 112px;
                    "
                >
                    <div
                        v-for="row in bars(question)"
                        :key="row.day"
                        :title="row.day"
                        style="
                            position: relative;
                            flex: 1;
                            height: 112px;
                            display: flex;
                            align-items: flex-end;
                        "
                    >
                        <div
                            style="width: 100%; background: #1b2224"
                            :style="{ height: `${row.totalHeight}px` }"
                        />
                        <div
                            v-if="row.partHeight"
                            style="
                                position: absolute;
                                bottom: 0;
                                left: 0;
                                width: 100%;
                                background: var(--mk-accent);
                                opacity: 0.8;
                            "
                            :style="{ height: `${row.partHeight}px` }"
                        />
                    </div>
                </div>
                <div
                    style="
                        margin-top: 9px;
                        display: flex;
                        align-items: center;
                        gap: 16px;
                    "
                >
                    <span
                        class="mk-k"
                        style="display: flex; align-items: center; gap: 6px"
                    >
                        <span
                            style="width: 11px; height: 7px; background: #1b2224"
                        />
                        {{ t(legend(question).total) }}
                    </span>
                    <span
                        v-if="legend(question).part"
                        class="mk-k"
                        style="display: flex; align-items: center; gap: 6px"
                    >
                        <span
                            style="
                                width: 11px;
                                height: 7px;
                                background: var(--mk-accent);
                                opacity: 0.8;
                            "
                        />
                        {{ t(legend(question).part as string) }}
                    </span>
                    <span
                        class="mk-m mk-t3"
                        style="margin-left: auto; font-size: 11px"
                        >{{ filters.from }} → {{ filters.to }}</span
                    >
                </div>
            </template>

            <!-- The funnel, with the share of the previous step on the right:
                 it says where people are lost, not how bad things are. -->
            <template v-else-if="question.evidence.type === 'funnel'">
                <div
                    v-for="(step, index) in steps(question)"
                    :key="step.key"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 14px;
                        padding: 6px 0;
                    "
                >
                    <span
                        style="width: 186px; flex: 0 0 186px; font-size: 12.5px"
                        >{{ t(`step.${step.key}`) }}</span
                    >
                    <div
                        style="
                            flex: 1;
                            height: 22px;
                            background: rgba(232, 236, 236, 0.05);
                        "
                    >
                        <div
                            style="height: 100%"
                            :style="{
                                width: `${step.of_top ?? 0}%`,
                                background:
                                    index === steps(question).length - 1
                                        ? 'var(--mk-accent)'
                                        : 'rgba(0,229,209,.35)',
                            }"
                        />
                    </div>
                    <span
                        class="mk-num"
                        style="width: 62px; text-align: right; font-size: 13px"
                        >{{ num(step.value) }}</span
                    >
                    <span
                        class="mk-m mk-t3"
                        style="width: 82px; text-align: right; font-size: 11.5px"
                        >{{
                            step.of_previous === null
                                ? '—'
                                : percent(step.of_previous)
                        }}</span
                    >
                </div>
                <p class="mk-t3" style="margin: 10px 0 0; font-size: 11px">
                    {{ t('numbers.money.note') }}
                </p>
            </template>

            <!-- Cohorts. A bucket that has not aged is named, never zero. -->
            <template v-else-if="question.evidence.type === 'cohorts'">
                <div class="mk-scroll-x">
                    <table class="mk-table">
                        <thead>
                            <tr>
                                <th style="padding-left: 0">
                                    {{ t('numbers.week') }}
                                </th>
                                <th style="text-align: right">
                                    {{ t('numbers.cohort') }}
                                </th>
                                <th
                                    v-for="bucket in cohortBuckets"
                                    :key="bucket"
                                    style="text-align: right"
                                >
                                    {{ bucket.toUpperCase() }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!cohortRows(question).length">
                                <td
                                    class="mk-t3"
                                    colspan="5"
                                    style="padding-left: 0; font-size: 12px"
                                >
                                    {{ t('numbers.empty') }}
                                </td>
                            </tr>
                            <tr
                                v-for="row in cohortRows(question)"
                                :key="row.week"
                            >
                                <td
                                    class="mk-m mk-t2"
                                    style="padding-left: 0; font-size: 12px"
                                >
                                    {{ row.week }}
                                </td>
                                <td
                                    class="mk-m"
                                    style="text-align: right; font-size: 12px"
                                >
                                    {{ num(row.size) }}
                                </td>
                                <td
                                    v-for="bucket in cohortBuckets"
                                    :key="bucket"
                                    class="mk-m"
                                    style="text-align: right; font-size: 12px"
                                    :style="{
                                        color:
                                            row.rates[bucket] === null
                                                ? 'var(--mk-fainter)'
                                                : 'var(--mk-body)',
                                    }"
                                >
                                    {{
                                        row.rates[bucket] === null
                                            ? t('numbers.return.immature')
                                            : percent(row.rates[bucket])
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Sources, sorted by who stays rather than by who arrives. -->
            <template v-else-if="question.evidence.type === 'sources'">
                <div class="mk-scroll-x">
                    <table class="mk-table">
                        <thead>
                            <tr>
                                <th style="padding-left: 0">
                                    {{ t('numbers.source') }}
                                </th>
                                <th>{{ t('numbers.campaign') }}</th>
                                <th style="text-align: right">
                                    {{ t('numbers.installs') }}
                                </th>
                                <th style="text-align: right">
                                    {{ t('numbers.activation') }}
                                </th>
                                <th style="text-align: right">D7</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!sourceRows(question).length">
                                <td
                                    class="mk-t3"
                                    colspan="5"
                                    style="padding-left: 0; font-size: 12px"
                                >
                                    {{ t('numbers.empty') }}
                                </td>
                            </tr>
                            <tr
                                v-for="row in sourceRows(question)"
                                :key="`${row.source}:${row.campaign}`"
                            >
                                <td style="padding-left: 0; font-size: 12.5px">
                                    {{ row.source }}
                                </td>
                                <td
                                    class="mk-m mk-t3"
                                    style="font-size: 11.5px"
                                >
                                    {{ row.campaign }}
                                </td>
                                <td
                                    class="mk-m"
                                    style="text-align: right; font-size: 12px"
                                >
                                    {{ num(row.users) }}
                                </td>
                                <td
                                    class="mk-m"
                                    style="text-align: right; font-size: 12px"
                                >
                                    {{ percent(row.activation_rate) }}
                                </td>
                                <td
                                    class="mk-m"
                                    style="text-align: right; font-size: 12px"
                                    :style="{
                                        color:
                                            row.d7 === null
                                                ? 'var(--mk-fainter)'
                                                : 'var(--mk-accent)',
                                    }"
                                >
                                    {{ percent(row.d7) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Failures, grouped so they can be acted on. -->
            <template v-else-if="question.evidence.type === 'errors'">
                <div
                    v-for="row in errorRows(question)"
                    :key="`${row.event}:${row.error_code}`"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 14px;
                        padding: 10px 0;
                    "
                >
                    <span
                        class="mk-m"
                        style="width: 190px; flex: 0 0 190px; font-size: 12px"
                        >{{ row.error_code }}</span
                    >
                    <span
                        class="mk-m mk-t3"
                        style="width: 140px; flex: 0 0 140px; font-size: 11.5px"
                        >{{ row.event }}</span
                    >
                    <span style="flex: 1" />
                    <span
                        class="mk-num"
                        style="width: 40px; text-align: right; font-size: 13px"
                        >{{ num(row.total) }}</span
                    >
                    <span
                        class="mk-m mk-t3"
                        style="width: 80px; text-align: right; font-size: 11px"
                        >{{ num(row.users) }} {{ t('numbers.people') }}</span
                    >
                </div>
            </template>

            <!-- Failures, and the honest empty state. -->
            <p
                v-if="
                    question.evidence.type === 'errors' &&
                    !errorRows(question).length
                "
                class="mk-t3"
                style="font-size: 12px"
            >
                {{ t('numbers.nothingBroke') }}
            </p>

            <!-- Bridge request statuses, for the sessions subject. -->
            <template v-else-if="question.evidence.type === 'statuses'">
                <div
                    v-for="row in statusRows(question)"
                    :key="row.status"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 14px;
                        padding: 10px 0;
                    "
                >
                    <span class="mk-m" style="font-size: 12px">{{
                        row.status
                    }}</span>
                    <span
                        class="mk-num"
                        style="margin-left: auto; font-size: 13px"
                        >{{ num(row.total) }}</span
                    >
                </div>
            </template>

            <!-- The cost tiles, all of them out of the payout ledger. -->
            <template v-else-if="question.evidence.type === 'tiles'">
                <div class="mk-tile-row">
                    <div
                        v-for="row in tileRows(question)"
                        :key="row.key"
                        class="mk-tile"
                        style="padding: 12px 14px"
                    >
                        <p class="mk-k" style="margin: 0">
                            {{ t(`numbers.cost.${row.key}`) }}
                        </p>
                        <p
                            class="mk-num"
                            style="margin: 6px 0 0; font-size: 20px"
                        >
                            {{
                                row.unit === 'usd'
                                    ? usd(row.value)
                                    : num(row.value)
                            }}
                        </p>
                        <p
                            class="mk-t3"
                            style="margin: 3px 0 0; font-size: 11px"
                        >
                            {{ t(row.note, row.params) }}
                        </p>
                    </div>
                </div>
                <p class="mk-t3" style="margin: 10px 0 0; font-size: 11px">
                    {{ t('numbers.cost.ledgerNote') }}
                </p>
            </template>

            <!-- A question this subject cannot answer says so, hatched, and
                 never borrows the other subject's number. -->
            <template v-else-if="question.evidence.type === 'unmeasured'">
                <div
                    class="mk-hatch"
                    style="
                        display: flex;
                        align-items: center;
                        padding: 22px 18px;
                        min-height: 96px;
                    "
                >
                    <span class="mk-t2" style="font-size: 12.5px">{{
                        t(question.evidence.note as string)
                    }}</span>
                </div>
            </template>
        </div>
    </div>
</template>
