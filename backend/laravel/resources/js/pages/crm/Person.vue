<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import { dateTime, num, shortDate, toneColor, usd } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * One person's dossier.
 *
 * The four panels that each held a slice of the record are one stream now:
 * visits, transfers, our messages and notes in the order they happened, which
 * is the only order in which a story reads. What is deliberately absent is a
 * balance curve — this app keeps no balance history, so the small chart is
 * transfers per week, which is a thing that is actually recorded.
 */
type Timeline = {
    kind: string;
    at: string | null;
    title: string;
    params: Record<string, string | number>;
    body: string | null;
    amount: { value: string; token: string; outbound: boolean } | null;
};

const props = defineProps<{
    contact: {
        id: number;
        name: string;
        telegram: string | null;
        email: string | null;
        evm_address: string | null;
        solana_address: string | null;
        type: string;
        status: string;
        source: string;
        tags: string[];
        created_at: string | null;
        last_synced_at: string | null;
    };
    money: {
        cyber: number;
        cyber_usd: number | null;
        cyber_sol: number;
        cyber_sol_usd: number | null;
        price: number | null;
    };
    activity: number[];
    summary: {
        tone: string;
        key: string;
        params: Record<string, string | number>;
    };
    tasks: {
        id: number;
        title: string;
        status: string;
        priority: string;
        due_at: string | null;
        overdue: boolean;
        assignee: string | null;
    }[];
    timeline: Timeline[];
}>();

const { t, tag } = useLocale(consoleMessages);

const note = useForm({ body: '' });

const overdue = computed(() => props.tasks.filter((task) => task.overdue).length);

const summaryText = computed(() => {
    const params = { ...props.summary.params };

    if (typeof params.source === 'string') {
        params.source = t(`crm.source.${params.source}`);
    }

    return t(props.summary.key, params);
});

const hasActivity = computed(() => props.activity.some((value) => value > 0));

const bars = computed(() => {
    const max = Math.max(1, ...props.activity);

    return props.activity.map((value) => ({
        value,
        height: Math.max(2, Math.round((value / max) * 40)),
    }));
});

function short(address: string | null): string {
    if (!address) {
        return t('person.none');
    }

    return address.length > 14
        ? `${address.slice(0, 6)}…${address.slice(-4)}`
        : address;
}

function eventText(row: Timeline): string {
    const params = { ...row.params };

    if (typeof params.source === 'string') {
        params.source = t(`crm.source.${params.source}`);
    }

    return t(row.title, params);
}

function saveNote() {
    note.post(`/crm/${props.contact.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => note.reset(),
    });
}

function remove() {
    router.delete(`/crm/${props.contact.id}`);
}
</script>

<template>
    <Head :title="`Мостик · ${contact.name}`" />

    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap">
        <Link href="/crm/people" class="mk-btn mk-ghost" style="padding: 0 8px"
            >← {{ t('person.back') }}</Link
        >
        <h1 class="mk-h1">{{ contact.name }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{ contact.telegram ?? short(contact.evm_address) }} ·
            {{ t('person.since') }} {{ shortDate(contact.created_at, tag) }} ·
            {{ t('person.source').toLowerCase() }}
            {{ t('crm.source.' + contact.source) }}
        </span>
        <span
            class="mk-tag"
            :style="
                contact.type === 'whale'
                    ? {
                          borderColor: 'rgba(255,43,214,.4)',
                          color: 'var(--mk-money)',
                      }
                    : {}
            "
            >{{ t(`crm.type.${contact.type}`) }}</span
        >
        <span class="mk-tag">{{ t(`crm.status.${contact.status}`) }}</span>
        <div style="margin-left: auto; display: flex; gap: 8px">
            <a
                v-if="contact.telegram"
                :href="`https://t.me/${contact.telegram.replace('@', '')}`"
                target="_blank"
                rel="noreferrer"
                class="mk-btn mk-act"
                >{{ t('person.write') }}</a
            >
            <button type="button" class="mk-btn mk-ghost" @click="remove">
                {{ t('person.delete') }}
            </button>
        </div>
    </div>

    <!-- The one sentence: only what is on the record, in the order that
         decides what to do next. -->
    <div
        style="padding: 14px 18px; border-left: 2px solid"
        :style="{
            borderLeftColor: toneColor(summary.tone),
            background: `color-mix(in srgb, ${toneColor(summary.tone)} 5%, transparent)`,
        }"
    >
        <p
            style="
                margin: 0;
                font-size: 13.5px;
                line-height: 1.6;
                color: var(--mk-body);
            "
        >
            {{ summaryText }}
        </p>
    </div>

    <div
        style="
            flex: 1;
            min-height: 0;
            display: grid;
            grid-template-columns: 322px minmax(0, 1fr);
            gap: 24px;
        "
    >
        <div style="display: flex; flex-direction: column; gap: 18px">
            <div>
                <Rule :label="t('person.money')" />
                <div class="mk-tile-row" style="margin-top: 12px">
                    <div class="mk-tile" style="padding: 12px 14px">
                        <p class="mk-k" style="margin: 0">CYBER</p>
                        <p class="mk-num" style="margin: 6px 0 0; font-size: 19px">
                            {{ num(money.cyber) }}
                        </p>
                        <p class="mk-t3" style="margin: 2px 0 0; font-size: 11px">
                            {{ usd(money.cyber_usd) }}
                        </p>
                    </div>
                    <div class="mk-tile" style="padding: 12px 14px">
                        <p class="mk-k" style="margin: 0">CYBER.sol</p>
                        <p class="mk-num" style="margin: 6px 0 0; font-size: 19px">
                            {{ num(money.cyber_sol) }}
                        </p>
                        <p class="mk-t3" style="margin: 2px 0 0; font-size: 11px">
                            {{ usd(money.cyber_sol_usd) }}
                        </p>
                    </div>
                </div>

                <div class="mk-panel" style="margin-top: 12px; padding: 12px 14px">
                    <div style="display: flex; align-items: baseline">
                        <p class="mk-k" style="margin: 0">
                            {{ t('person.activity') }}
                        </p>
                    </div>
                    <div
                        v-if="hasActivity"
                        style="
                            margin-top: 10px;
                            display: flex;
                            align-items: flex-end;
                            gap: 3px;
                            height: 40px;
                        "
                    >
                        <div
                            v-for="(bar, index) in bars"
                            :key="index"
                            :style="{
                                flex: 1,
                                height: `${bar.height}px`,
                                background:
                                    bar.value > 0
                                        ? 'var(--mk-money)'
                                        : 'var(--mk-flat)',
                                opacity: bar.value > 0 ? 0.8 : 1,
                            }"
                        />
                    </div>
                    <p class="mk-t3" style="margin: 8px 0 0; font-size: 11px">
                        {{
                            hasActivity
                                ? t('person.activityNote')
                                : t('person.noActivity')
                        }}
                    </p>
                </div>
            </div>

            <div>
                <Rule :label="t('person.who')" />
                <div style="margin-top: 10px">
                    <div
                        v-for="row in [
                            { label: t('person.evm'), value: short(contact.evm_address) },
                            { label: t('person.solana'), value: short(contact.solana_address) },
                            { label: t('person.telegram'), value: contact.telegram ?? t('person.none') },
                            { label: t('person.email'), value: contact.email ?? t('person.none') },
                            { label: t('person.tags'), value: contact.tags.length ? contact.tags.join(' · ') : t('unit.none') },
                            { label: t('person.lastSync'), value: contact.last_synced_at ? dateTime(contact.last_synced_at, tag) : t('unit.none') },
                        ]"
                        :key="row.label"
                        class="mk-hair"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 12px;
                            padding: 8px 0;
                        "
                    >
                        <span class="mk-t3" style="font-size: 12px">{{
                            row.label
                        }}</span>
                        <span
                            class="mk-m mk-clip"
                            style="
                                margin-left: auto;
                                font-size: 12px;
                                color: var(--mk-body);
                            "
                            >{{ row.value }}</span
                        >
                    </div>
                </div>
            </div>

            <div>
                <Rule
                    :label="t('person.next')"
                    :note="
                        overdue > 0
                            ? t('person.overdueCount', { count: overdue })
                            : null
                    "
                />
                <div
                    style="
                        margin-top: 10px;
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                    "
                >
                    <div
                        v-for="task in tasks"
                        :key="task.id"
                        class="mk-panel"
                        style="display: flex; gap: 11px; padding: 11px 13px 11px 0"
                    >
                        <span
                            style="width: 2px; flex: 0 0 2px"
                            :style="{
                                background: task.overdue
                                    ? 'var(--mk-critical)'
                                    : 'var(--mk-warning)',
                            }"
                        />
                        <div>
                            <p
                                style="
                                    margin: 0;
                                    font-size: 12.5px;
                                    font-weight: 500;
                                "
                            >
                                {{ task.title }}
                            </p>
                            <p
                                class="mk-m"
                                style="margin: 5px 0 0; font-size: 11px"
                                :style="{
                                    color: task.overdue
                                        ? 'var(--mk-critical)'
                                        : 'var(--mk-faint)',
                                }"
                            >
                                {{ task.assignee ?? t('tasks.nobody') }} ·
                                {{
                                    task.due_at
                                        ? shortDate(task.due_at, tag)
                                        : t('tasks.noDue')
                                }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="!tasks.length"
                        class="mk-t3"
                        style="font-size: 12px"
                    >
                        {{ t('person.noTasks') }}
                    </p>
                </div>
            </div>
        </div>

        <div style="display: flex; flex-direction: column; min-width: 0">
            <Rule
                :label="t('person.everything')"
                :note="t('person.everythingNote')"
            />

            <form
                style="margin-top: 12px; display: flex; gap: 8px"
                @submit.prevent="saveNote"
            >
                <input
                    v-model="note.body"
                    class="mk-input"
                    style="flex: 1"
                    :placeholder="t('person.addNote')"
                />
                <button
                    type="submit"
                    class="mk-btn mk-act"
                    :disabled="note.processing || !note.body"
                >
                    {{ t('person.saveNote') }}
                </button>
            </form>

            <div style="margin-top: 12px">
                <div
                    v-for="(row, index) in timeline"
                    :key="index"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: flex-start;
                        gap: 14px;
                        padding: 12px 2px;
                    "
                >
                    <span
                        class="mk-m mk-t3"
                        style="
                            width: 108px;
                            flex: 0 0 108px;
                            font-size: 11.5px;
                            padding-top: 2px;
                        "
                        >{{ dateTime(row.at, tag) }}</span
                    >
                    <span
                        style="
                            width: 26px;
                            flex: 0 0 26px;
                            height: 26px;
                            border: 1px solid var(--mk-hair-strong);
                            display: flex;
                            align-items: center;
                            justify-content: center;
                        "
                        :style="{
                            color:
                                row.kind === 'bridge'
                                    ? 'var(--mk-warning)'
                                    : row.kind === 'note'
                                      ? 'var(--mk-accent)'
                                      : 'var(--mk-faint)',
                        }"
                    >
                        <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.6"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                v-if="row.kind === 'bridge'"
                                d="M3 18V9M21 18V9M3 13c5-4.5 13-4.5 18 0"
                            />
                            <template v-else-if="row.kind === 'note'">
                                <path d="M5 4h14v16H5z" />
                                <path d="M8 9h8M8 13h5" />
                            </template>
                            <template v-else-if="row.kind === 'task'">
                                <rect x="4" y="4" width="16" height="16" rx="2" />
                                <path d="M8 12l3 3 5-6" />
                            </template>
                            <template v-else>
                                <circle cx="12" cy="12" r="8" />
                                <path d="M12 8v4l3 2" />
                            </template>
                        </svg>
                    </span>
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 13px; font-weight: 500">
                            {{ eventText(row) }}
                        </div>
                        <div
                            v-if="row.body"
                            class="mk-t3"
                            style="margin-top: 3px; font-size: 11.5px"
                        >
                            {{ row.body }}
                        </div>
                    </div>
                    <span
                        v-if="row.amount"
                        class="mk-num"
                        style="font-size: 13px"
                        :style="{
                            color: row.amount.outbound
                                ? 'var(--mk-warning)'
                                : 'var(--mk-dim)',
                        }"
                    >
                        {{ row.amount.outbound ? '−' : '+' }}{{ row.amount.value }}
                        {{ row.amount.token }}
                    </span>
                </div>
                <p
                    v-if="!timeline.length"
                    class="mk-t3"
                    style="font-size: 12px"
                >
                    {{ t('person.noHistory') }}
                </p>
            </div>
        </div>
    </div>
</template>
