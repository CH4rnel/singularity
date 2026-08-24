<script setup lang="ts">
import { Head, Link, useHttp } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { store } from '@/actions/App/Http/Controllers/ConsoleAiKeysController';
import { useLocale } from '@/composables/useLocale';
import { age, dateTime, num, plural, secondsSince } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

type Usage = {
    requests: number;
    tokens: number;
};

type KeyRow = {
    id: number;
    name: string | null;
    prefix: string;
    address: string;
    instance_id: string;
    owner: { id: number; name: string } | null;
    contact: { id: number; name: string | null } | null;
    status: 'active' | 'waiting' | 'revoked';
    state_since: string | null;
    created_at: string | null;
    last_used_at: string | null;
    revoked_at: string | null;
    usage: { today: Usage; lifetime: Usage };
};

type IssueResponse = {
    token: string;
    key: {
        id: number;
        name: string | null;
        prefix: string;
        address: string;
        instance_id: string;
        created_at: string;
    };
};

const props = defineProps<{
    summary: {
        total: number;
        active: number;
        waiting: number;
        revoked: number;
        used_today: number;
        requests_today: number;
        tokens_today: number;
    };
    keys: KeyRow[];
    row_limit: number;
}>();

const { locale, t, tag } = useLocale(consoleMessages);
const rows = ref([...props.keys]);
const totals = reactive({ ...props.summary });
const issueForm = useHttp<{ address: string; name: string }, IssueResponse>({
    address: '',
    name: '',
});
const issued = ref<IssueResponse | null>(null);
const copied = ref(false);
const issueFailed = ref(false);
const setupText = computed(() =>
    issued.value === null
        ? ''
        : `LAINOS_MODEL_PROVIDER=cyberia\nCYBERIA_AI_KEY=${issued.value.token}`,
);

async function issueKey(): Promise<void> {
    issued.value = null;
    copied.value = false;
    issueFailed.value = false;

    try {
        const result = await issueForm.submit(store());
        issued.value = result;
        rows.value.unshift({
            ...result.key,
            owner: null,
            contact: null,
            status: 'waiting',
            state_since: result.key.created_at,
            last_used_at: null,
            revoked_at: null,
            usage: {
                today: { requests: 0, tokens: 0 },
                lifetime: { requests: 0, tokens: 0 },
            },
        });
        totals.total += 1;
        totals.waiting += 1;
        issueForm.reset();
    } catch {
        issueFailed.value = !issueForm.hasErrors;
    }
}

async function copySetup(): Promise<void> {
    await navigator.clipboard.writeText(setupText.value);
    copied.value = true;
}

function ago(iso: string | null): string {
    const value = age(secondsSince(iso));

    return value === null
        ? '—'
        : `${value.value} ${plural(locale.value, value.count, t(value.unit))}`;
}

function shortAddress(address: string): string {
    return `${address.slice(0, 8)}…${address.slice(-6)}`;
}

function stateColor(status: KeyRow['status']): string {
    if (status === 'revoked') {
        return 'var(--mk-critical)';
    }

    if (status === 'waiting') {
        return 'var(--mk-warning)';
    }

    return 'var(--mk-faint)';
}
</script>

<template>
    <Head title="Пульт · LainOS API" />

    <div
        style="display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap"
    >
        <h1 class="mk-h1">{{ t('keys.title') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{ t('keys.subtitle') }}
        </span>
    </div>

    <div class="mk-grid" style="margin-top: 18px">
        <div class="mk-panel" style="padding: 15px">
            <div class="mk-k">{{ t('keys.total') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(totals.total) }}
            </div>
        </div>
        <div class="mk-panel" style="padding: 15px">
            <div class="mk-k">{{ t('keys.active') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(totals.active) }}
            </div>
        </div>
        <div class="mk-panel" style="padding: 15px">
            <div class="mk-k">{{ t('keys.waiting') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(totals.waiting) }}
            </div>
        </div>
        <div
            class="mk-panel"
            style="padding: 15px"
            :style="
                totals.revoked > 0 ? { borderColor: 'rgba(255,77,77,.4)' } : {}
            "
        >
            <div class="mk-k">{{ t('keys.revoked') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(totals.revoked) }}
            </div>
        </div>
    </div>

    <div
        class="mk-panel"
        style="
            margin-top: 12px;
            padding: 12px 15px;
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        "
    >
        <span class="mk-t2" style="font-size: 12px">
            {{ t('keys.today') }}:
            <strong class="mk-num">{{ num(totals.used_today) }}</strong>
            {{ t('keys.instances') }} ·
            <strong class="mk-num">{{ num(totals.requests_today) }}</strong>
            {{ t('keys.requests') }} ·
            <strong class="mk-num">{{ num(totals.tokens_today) }}</strong>
            {{ t('keys.tokens') }}
        </span>
        <span class="mk-t3" style="margin-left: auto; font-size: 11.5px">
            {{ t('keys.secretNote') }}
        </span>
    </div>

    <section class="mk-panel" style="margin-top: 18px; padding: 15px">
        <div
            style="
                display: flex;
                align-items: baseline;
                gap: 12px;
                flex-wrap: wrap;
            "
        >
            <h2 class="mk-h2">{{ t('keys.issueTitle') }}</h2>
            <span class="mk-t3" style="font-size: 11.5px">
                {{ t('keys.issueNote') }}
            </span>
        </div>

        <form
            style="margin-top: 13px; display: flex; gap: 9px; flex-wrap: wrap"
            @submit.prevent="issueKey"
        >
            <div style="flex: 2 1 370px">
                <input
                    v-model="issueForm.address"
                    class="mk-input mk-m"
                    autocomplete="off"
                    :placeholder="t('keys.addressPlaceholder')"
                />
                <div
                    v-if="issueForm.errors.address"
                    style="
                        margin-top: 5px;
                        color: var(--mk-critical);
                        font-size: 11px;
                    "
                >
                    {{ issueForm.errors.address }}
                </div>
            </div>
            <div style="flex: 1 1 220px">
                <input
                    v-model="issueForm.name"
                    class="mk-input"
                    autocomplete="off"
                    :placeholder="t('keys.namePlaceholder')"
                />
                <div
                    v-if="issueForm.errors.name"
                    style="
                        margin-top: 5px;
                        color: var(--mk-critical);
                        font-size: 11px;
                    "
                >
                    {{ issueForm.errors.name }}
                </div>
            </div>
            <button
                class="mk-btn mk-act"
                type="submit"
                :disabled="issueForm.processing"
            >
                {{
                    issueForm.processing
                        ? t('keys.issuing')
                        : t('keys.issueAction')
                }}
            </button>
        </form>

        <div
            v-if="issueFailed"
            style="margin-top: 10px; color: var(--mk-critical); font-size: 12px"
        >
            {{ t('keys.issueFailed') }}
        </div>

        <div
            v-if="issued"
            style="
                margin-top: 14px;
                border-top: 1px solid var(--mk-line);
                padding-top: 13px;
            "
        >
            <div class="mk-k">{{ t('keys.issuedOnce') }}</div>
            <pre
                class="mk-m"
                style="
                    margin: 8px 0 0;
                    white-space: pre-wrap;
                    overflow-wrap: anywhere;
                    color: var(--mk-text);
                "
                >{{ setupText }}</pre
            >
            <div
                style="
                    margin-top: 10px;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    flex-wrap: wrap;
                "
            >
                <button
                    class="mk-btn mk-ghost"
                    type="button"
                    @click="copySetup"
                >
                    {{ copied ? t('keys.copied') : t('keys.copySetup') }}
                </button>
                <span class="mk-t3" style="font-size: 11px">
                    {{
                        t('keys.instanceCreated', {
                            id: issued.key.instance_id,
                        })
                    }}
                </span>
            </div>
        </div>
    </section>

    <div
        style="
            margin-top: 25px;
            display: flex;
            align-items: baseline;
            gap: 12px;
        "
    >
        <h2 class="mk-h2">{{ t('keys.instances') }}</h2>
        <span class="mk-t3" style="font-size: 11.5px">
            {{ t('keys.instancesNote', { limit: row_limit }) }}
        </span>
    </div>

    <div class="mk-scroll-x" style="margin-top: 10px">
        <table class="mk-table" style="min-width: 1180px">
            <thead>
                <tr>
                    <th style="padding-left: 0">{{ t('keys.age') }}</th>
                    <th>{{ t('keys.owner') }}</th>
                    <th>{{ t('keys.instance') }}</th>
                    <th>{{ t('keys.key') }}</th>
                    <th>{{ t('keys.state') }}</th>
                    <th style="text-align: right">
                        {{ t('keys.todayUsage') }}
                    </th>
                    <th style="text-align: right">{{ t('keys.lifetime') }}</th>
                    <th style="text-align: right">{{ t('keys.lastUsed') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0">
                    <td colspan="8" class="mk-t3" style="padding-left: 0">
                        {{ t('keys.empty') }}
                    </td>
                </tr>
                <tr v-for="key in rows" :key="key.id">
                    <td
                        class="mk-m"
                        style="padding-left: 0; white-space: nowrap"
                    >
                        {{ ago(key.state_since) }}
                    </td>
                    <td>
                        <Link
                            v-if="key.contact"
                            :href="`/crm/${key.contact.id}`"
                            style="color: inherit; text-decoration: none"
                        >
                            <div style="font-weight: 600">
                                {{
                                    key.contact.name ??
                                    key.owner?.name ??
                                    t('keys.unknownOwner')
                                }}
                            </div>
                            <div
                                class="mk-m mk-t3"
                                style="margin-top: 3px; font-size: 10.5px"
                            >
                                {{ shortAddress(key.address) }}
                            </div>
                        </Link>
                        <template v-else>
                            <div style="font-weight: 600">
                                {{ key.owner?.name ?? t('keys.unknownOwner') }}
                            </div>
                            <div
                                class="mk-m mk-t3"
                                style="margin-top: 3px; font-size: 10.5px"
                            >
                                {{ shortAddress(key.address) }}
                            </div>
                        </template>
                    </td>
                    <td class="mk-m" style="font-size: 11px">
                        {{ key.instance_id }}
                    </td>
                    <td>
                        <div class="mk-m">{{ key.prefix }}…</div>
                        <div
                            class="mk-t3"
                            style="margin-top: 3px; font-size: 10.5px"
                        >
                            {{ key.name ?? '—' }} · #{{ key.id }}
                        </div>
                    </td>
                    <td style="white-space: nowrap">
                        <span
                            class="mk-dot"
                            :style="{
                                background: stateColor(key.status),
                                marginRight: '7px',
                            }"
                        />
                        {{ t(`keys.state.${key.status}`) }}
                    </td>
                    <td
                        class="mk-m"
                        style="text-align: right; white-space: nowrap"
                    >
                        {{ num(key.usage.today.requests) }} /
                        {{ num(key.usage.today.tokens) }}
                    </td>
                    <td
                        class="mk-m"
                        style="text-align: right; white-space: nowrap"
                    >
                        {{ num(key.usage.lifetime.requests) }} /
                        {{ num(key.usage.lifetime.tokens) }}
                    </td>
                    <td
                        class="mk-m mk-t2"
                        style="text-align: right; white-space: nowrap"
                    >
                        {{ dateTime(key.last_used_at, tag) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
