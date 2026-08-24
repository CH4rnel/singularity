<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useConsoleLive } from '@/composables/useConsolePulse';
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
        x_handle: string | null;
        /* Built by the server: a stored handle is not always an address. */
        telegram_url: string | null;
        x_url: string | null;
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
    /*
     * The same human, filed more than once.
     *
     * `same` are records joined by evidence that stands on its own — a key
     * attached to an account, an address that signed a deposit under it.
     * `links` includes the guesses too, each with what justified it, so a
     * surprising join can be argued with instead of merely believed.
     */
    identity: {
        nodes: string[];
        same: {
            id: number;
            name: string;
            source: string;
            evm_address: string | null;
            solana_address: string | null;
            user_id: number | null;
        }[];
        links: {
            id: number;
            left: string;
            right: string;
            source: string;
            confidence: string;
            evidence: string | null;
            created_at: string | null;
        }[];
    };
    options: {
        types: string[];
        statuses: string[];
    };
}>();

const { t, tag } = useLocale(consoleMessages);

const note = useForm({ body: '' });

/*
 * Correcting the record.
 *
 * Half of a dossier is what happened — visits, transfers, notes — and none of
 * that is editable, because it is a log. The other half is what somebody told
 * us: a name, a handle, which pile this person belongs in. That half is
 * always slightly wrong (a handle changes, a lead becomes a customer), and a
 * console that can only read it makes the operator keep the correction in
 * their head. So exactly the told half opens for editing, in place, and the
 * log underneath stays a log.
 */
const editing = ref(false);

/*
 * A dossier is read while somebody else is writing in it: a note added, a
 * task closed, the sync landing a new balance. So it re-reads itself — except
 * while the told half is open for editing, because replacing the record
 * under a form that is about to overwrite it is how two operators undo each
 * other's corrections.
 */
useConsoleLive(
    ['people', 'notes', 'tasks'],
    () =>
        router.reload({
            only: [
                'contact',
                'money',
                'activity',
                'summary',
                'tasks',
                'timeline',
                'identity',
                'console',
            ],
        }),
    { active: () => !editing.value },
);

const edit = useForm({
    name: '',
    telegram: '',
    x_handle: '',
    email: '',
    evm_address: '',
    solana_address: '',
    tags: '',
    type: props.contact.type,
    status: props.contact.status,
});

/* The told half of the record, in the order the read view lists it. */
type EditField =
    | 'name'
    | 'evm_address'
    | 'solana_address'
    | 'telegram'
    | 'x_handle'
    | 'email'
    | 'tags';

const editFields = computed<{ key: EditField; label: string; hint: string }[]>(
    () => [
        {
            key: 'name',
            label: t('person.name'),
            hint: t('person.namePlaceholder'),
        },
        { key: 'evm_address', label: t('person.evm'), hint: '0x…' },
        { key: 'solana_address', label: t('person.solana'), hint: '' },
        {
            key: 'telegram',
            label: t('person.telegram'),
            hint: t('person.handlePlaceholder'),
        },
        { key: 'x_handle', label: 'X', hint: t('person.handlePlaceholder') },
        { key: 'email', label: t('person.email'), hint: '' },
        {
            key: 'tags',
            label: t('person.tags'),
            hint: t('person.tagsPlaceholder'),
        },
    ],
);

function startEditing() {
    // Read from the record every time it is opened: another operator may have
    // changed it since this page was rendered.
    edit.defaults({
        name: props.contact.name,
        telegram: props.contact.telegram ?? '',
        x_handle: props.contact.x_handle ?? '',
        email: props.contact.email ?? '',
        evm_address: props.contact.evm_address ?? '',
        solana_address: props.contact.solana_address ?? '',
        tags: props.contact.tags.join(', '),
        type: props.contact.type,
        status: props.contact.status,
    });
    edit.reset();
    edit.clearErrors();
    editing.value = true;
}

function saveEdit() {
    if (edit.processing) {
        return;
    }

    edit.transform((data) => ({
        ...data,
        tags: data.tags
            .split(',')
            .map((value) => value.trim())
            .filter((value) => value !== ''),
    })).put(`/crm/${props.contact.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}

/** Linking by hand: an account id, an EVM address, a Solana address. */
const identityForm = useForm({ target: '' });

const suggestions = computed(() =>
    props.identity.links.filter((link) => link.confidence !== 'strong'),
);

const confirmed = computed(() =>
    props.identity.links.filter((link) => link.confidence === 'strong'),
);

/** `evm:0x89bb…` reads better than the forty characters it stands for. */
function nodeLabel(node: string): string {
    const [kind, ...rest] = node.split(':');
    const value = rest.join(':');

    return `${kind} ${value.length > 18 ? short(value) : value}`;
}

function linkIdentity() {
    identityForm.post(`/crm/${props.contact.id}/identity`, {
        preserveScroll: true,
        onSuccess: () => identityForm.reset(),
    });
}

function confirmLink(id: number) {
    router.post(
        `/crm/identity-links/${id}/confirm`,
        {},
        { preserveScroll: true },
    );
}

function withdrawLink(id: number) {
    router.delete(`/crm/identity-links/${id}`, { preserveScroll: true });
}

const overdue = computed(
    () => props.tasks.filter((task) => task.overdue).length,
);

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
    <Head :title="`Пульт · ${contact.name}`" />

    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap">
        <Link href="/crm/people" class="mk-btn mk-ghost" style="padding: 0 8px"
            >← {{ t('person.back') }}</Link
        >
        <h1 class="mk-h1">{{ contact.name }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{
                contact.telegram ??
                (contact.x_handle
                    ? `@${contact.x_handle}`
                    : short(contact.evm_address))
            }}
            ·
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
                v-if="contact.telegram_url"
                :href="contact.telegram_url"
                target="_blank"
                rel="noreferrer"
                class="mk-btn mk-act"
                >{{ t('person.write') }}</a
            >
            <a
                v-if="contact.x_url"
                :href="contact.x_url"
                target="_blank"
                rel="noreferrer"
                class="mk-btn"
                :class="{ 'mk-act': !contact.telegram_url }"
                >{{ t('person.writeX') }}</a
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
                        <p
                            class="mk-num"
                            style="margin: 6px 0 0; font-size: 19px"
                        >
                            {{ num(money.cyber) }}
                        </p>
                        <p
                            class="mk-t3"
                            style="margin: 2px 0 0; font-size: 11px"
                        >
                            {{ usd(money.cyber_usd) }}
                        </p>
                    </div>
                    <div class="mk-tile" style="padding: 12px 14px">
                        <p class="mk-k" style="margin: 0">CYBER.sol</p>
                        <p
                            class="mk-num"
                            style="margin: 6px 0 0; font-size: 19px"
                        >
                            {{ num(money.cyber_sol) }}
                        </p>
                        <p
                            class="mk-t3"
                            style="margin: 2px 0 0; font-size: 11px"
                        >
                            {{ usd(money.cyber_sol_usd) }}
                        </p>
                    </div>
                </div>

                <div
                    class="mk-panel"
                    style="margin-top: 12px; padding: 12px 14px"
                >
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
                <Rule :label="t('person.who')">
                    <button
                        v-if="!editing"
                        type="button"
                        class="mk-btn mk-ghost"
                        style="height: 22px; padding: 0 6px"
                        @click="startEditing"
                    >
                        {{ t('person.edit') }}
                    </button>
                </Rule>

                <!-- Reading. The handles are the addresses this person is
                     reachable at, so they are links wherever the server could
                     build one, and plain text wherever it could not. -->
                <div v-if="!editing" style="margin-top: 10px">
                    <div
                        v-for="row in [
                            {
                                label: t('person.evm'),
                                value: short(contact.evm_address),
                                href: null,
                            },
                            {
                                label: t('person.solana'),
                                value: short(contact.solana_address),
                                href: null,
                            },
                            {
                                label: t('person.telegram'),
                                value: contact.telegram ?? t('person.none'),
                                href: contact.telegram_url,
                            },
                            {
                                label: t('person.x'),
                                value: contact.x_handle
                                    ? `@${contact.x_handle}`
                                    : t('person.none'),
                                href: contact.x_url,
                            },
                            {
                                label: t('person.email'),
                                value: contact.email ?? t('person.none'),
                                href: contact.email
                                    ? `mailto:${contact.email}`
                                    : null,
                            },
                            {
                                label: t('person.tags'),
                                value: contact.tags.length
                                    ? contact.tags.join(' · ')
                                    : t('unit.none'),
                                href: null,
                            },
                            {
                                label: t('person.lastSync'),
                                value: contact.last_synced_at
                                    ? dateTime(contact.last_synced_at, tag)
                                    : t('unit.none'),
                                href: null,
                            },
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
                        <a
                            v-if="row.href"
                            :href="row.href"
                            target="_blank"
                            rel="noreferrer"
                            class="mk-m mk-clip"
                            style="
                                margin-left: auto;
                                font-size: 12px;
                                color: var(--mk-accent);
                                text-decoration: none;
                            "
                            >{{ row.value }}</a
                        >
                        <span
                            v-else
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

                <!-- Correcting. The same fields, in the same order, in the
                     same place — an edit screen somewhere else would be a
                     second version of this panel to keep in step with it. -->
                <form
                    v-else
                    style="
                        margin-top: 10px;
                        display: flex;
                        flex-direction: column;
                        gap: 7px;
                    "
                    @submit.prevent="saveEdit"
                >
                    <!-- Labelled rows rather than a stack of placeholders: a
                         filled field has no placeholder left, and six
                         identical boxes holding six handles is a puzzle. The
                         labels sit where the read view puts them, so the panel
                         does not move when it opens. -->
                    <label
                        v-for="field in editFields"
                        :key="field.key"
                        style="display: flex; align-items: center; gap: 10px"
                    >
                        <span
                            class="mk-t3"
                            style="
                                width: 62px;
                                flex: 0 0 62px;
                                font-size: 11.5px;
                            "
                            >{{ field.label }}</span
                        >
                        <input
                            v-model="edit[field.key]"
                            class="mk-input"
                            style="flex: 1; min-width: 0"
                            :placeholder="field.hint"
                        />
                    </label>

                    <div
                        style="
                            margin-top: 3px;
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            flex-wrap: wrap;
                        "
                    >
                        <span class="mk-k">{{ t('person.editType') }}</span>
                        <button
                            v-for="value in options.types"
                            :key="value"
                            type="button"
                            class="mk-pick"
                            :class="{ 'mk-on': edit.type === value }"
                            @click="edit.type = value"
                        >
                            {{ t(`crm.type.${value}`) }}
                        </button>
                    </div>
                    <div
                        style="
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            flex-wrap: wrap;
                        "
                    >
                        <span class="mk-k">{{ t('person.editStatus') }}</span>
                        <button
                            v-for="value in options.statuses"
                            :key="value"
                            type="button"
                            class="mk-pick"
                            :class="{ 'mk-on': edit.status === value }"
                            @click="edit.status = value"
                        >
                            {{ t(`crm.status.${value}`) }}
                        </button>
                    </div>

                    <p
                        v-if="Object.keys(edit.errors).length"
                        style="
                            margin: 4px 0 0;
                            font-size: 11.5px;
                            line-height: 1.5;
                            color: var(--mk-critical);
                        "
                    >
                        {{ Object.values(edit.errors).join(' · ') }}
                    </p>

                    <div style="margin-top: 4px; display: flex; gap: 8px">
                        <button
                            type="submit"
                            class="mk-btn mk-act"
                            :disabled="edit.processing"
                        >
                            {{ t('person.editSave') }}
                        </button>
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            @click="editing = false"
                        >
                            {{ t('person.editCancel') }}
                        </button>
                    </div>

                    <p
                        class="mk-t3"
                        style="
                            margin: 2px 0 0;
                            font-size: 11px;
                            line-height: 1.5;
                        "
                    >
                        {{ t('person.editNote') }}
                    </p>
                </form>
            </div>

            <!-- The same human, filed more than once. Records stay separate;
                 what is asserted here is only that they are one person, and
                 every assertion says what justified it. -->
            <div style="margin-top: 22px">
                <Rule :label="t('person.identity')" />

                <p
                    v-if="!identity.same.length && !suggestions.length"
                    class="mk-t3"
                    style="margin: 10px 0 0; font-size: 11.5px"
                >
                    {{ t('person.identityNone') }}
                </p>

                <Link
                    v-for="other in identity.same"
                    :key="other.id"
                    :href="`/crm/${other.id}`"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        padding: 8px 0;
                        text-decoration: none;
                    "
                >
                    <span
                        class="mk-tag"
                        style="
                            color: var(--mk-cyan);
                            border-color: var(--mk-cyan);
                        "
                        >{{ t('person.identitySame') }}</span
                    >
                    <span
                        class="mk-clip"
                        style="font-size: 12px; color: var(--mk-body)"
                        >{{ other.name }}</span
                    >
                    <span
                        class="mk-m mk-t3"
                        style="margin-left: auto; font-size: 11px"
                        >{{ other.source }}</span
                    >
                </Link>

                <!-- Guesses. A bridge pays out to whatever address it was
                     given, and people pay their friends — so these wait for
                     somebody to look rather than joining on their own. -->
                <div
                    v-for="link in suggestions"
                    :key="link.id"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding: 8px 0;
                    "
                >
                    <span class="mk-tag">{{ t('person.identityMaybe') }}</span>
                    <span
                        class="mk-m mk-clip"
                        style="font-size: 11.5px; color: var(--mk-body)"
                        >{{ nodeLabel(link.left) }} ↔
                        {{ nodeLabel(link.right) }}</span
                    >
                    <span style="margin-left: auto; display: flex; gap: 6px">
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            style="padding: 0 8px"
                            @click="confirmLink(link.id)"
                        >
                            {{ t('person.identityConfirm') }}
                        </button>
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            style="padding: 0 8px"
                            @click="withdrawLink(link.id)"
                        >
                            {{ t('person.identityDrop') }}
                        </button>
                    </span>
                </div>

                <!-- Why, for each link that is holding. -->
                <details v-if="confirmed.length" style="margin-top: 10px">
                    <summary
                        class="mk-t3"
                        style="cursor: pointer; font-size: 11px"
                    >
                        {{
                            t('person.identityWhy', { count: confirmed.length })
                        }}
                    </summary>
                    <div
                        v-for="link in confirmed"
                        :key="link.id"
                        class="mk-hair"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 8px;
                            padding: 6px 0;
                        "
                    >
                        <span class="mk-m mk-clip mk-t3" style="font-size: 11px"
                            >{{ nodeLabel(link.left) }} ↔
                            {{ nodeLabel(link.right) }}</span
                        >
                        <span
                            class="mk-m mk-t3"
                            style="margin-left: auto; font-size: 10.5px"
                            >{{ link.evidence ?? link.source }}</span
                        >
                        <button
                            type="button"
                            class="mk-btn mk-ghost"
                            style="padding: 0 6px"
                            @click="withdrawLink(link.id)"
                        >
                            {{ t('person.identityDrop') }}
                        </button>
                    </div>
                </details>

                <form
                    style="display: flex; gap: 6px; margin-top: 12px"
                    @submit.prevent="linkIdentity"
                >
                    <input
                        v-model="identityForm.target"
                        class="mk-input mk-m"
                        style="flex: 1; min-width: 0; font-size: 11.5px"
                        :placeholder="t('person.identityPlaceholder')"
                    />
                    <button
                        type="submit"
                        class="mk-btn mk-ghost"
                        style="padding: 0 10px"
                        :disabled="
                            identityForm.processing || !identityForm.target
                        "
                    >
                        {{ t('person.identityAdd') }}
                    </button>
                </form>
                <p
                    v-if="identityForm.errors.target"
                    class="mk-t3"
                    style="
                        margin: 6px 0 0;
                        font-size: 11px;
                        color: var(--mk-red);
                    "
                >
                    {{ identityForm.errors.target }}
                </p>
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
                        style="
                            display: flex;
                            gap: 11px;
                            padding: 11px 13px 11px 0;
                        "
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
                                <rect
                                    x="4"
                                    y="4"
                                    width="16"
                                    height="16"
                                    rx="2"
                                />
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
                        {{ row.amount.outbound ? '−' : '+'
                        }}{{ row.amount.value }}
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
