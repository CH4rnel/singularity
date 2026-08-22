<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Rule from '@/components/console/Rule.vue';
import { useLocale } from '@/composables/useLocale';
import { age, dateTime, num, plural } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

/**
 * One installation of the wallet, as a timeline.
 *
 * It exists so a drop-off can be looked at instead of inferred from a
 * percentage. The identity is the anonymous id the app mints on first run and
 * never a wallet address: one person holds several, and counting addresses
 * would multiply every user — so the dossier shows how many are linked and
 * not which they are.
 */
type Event = {
    event: string;
    chain: string | null;
    properties: Record<string, unknown> | null;
    created_at: string;
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
        campaign: string | null;
        referrer: string | null;
        landing_path: string | null;
        wallet_created_at: string | null;
        funded_at: string | null;
        activated_at: string | null;
        first_transaction_at: string | null;
        linked_addresses: number;
    };
    timeline: Event[];
    sessions: { id: string }[];
    meaningful: string[];
    peers: { step: string; count: number; days: number };
}>();

const { locale, t, tag } = useLocale(consoleMessages);

const short = computed(
    () => `${props.user.id.slice(0, 4)}…${props.user.id.slice(-4)}`,
);

/**
 * The five steps, and the gap between the ones that happened.
 *
 * A step with no timestamp is drawn hollow and dated "—" rather than filled
 * optimistically: meaningful means settled on chain, and broadcasting is not
 * settlement.
 */
const milestones = computed(() => {
    const steps = [
        { key: 'opened', at: props.user.first_seen_at },
        { key: 'wallet', at: props.user.wallet_created_at },
        { key: 'funded', at: props.user.funded_at },
        { key: 'activated', at: props.user.activated_at },
        {
            key: 'returned',
            at:
                props.user.activated_at &&
                props.user.last_seen_at &&
                Date.parse(props.user.last_seen_at) -
                    Date.parse(props.user.activated_at) >
                    7 * 86_400_000
                    ? props.user.last_seen_at
                    : null,
        },
    ];

    return steps.map((step, index) => {
        const previous = steps[index - 1];
        let gap: string | null = null;
        let waiting = false;

        if (previous?.at && step.at) {
            const value = age(
                Math.floor(
                    (Date.parse(step.at) - Date.parse(previous.at)) / 1000,
                ),
            );

            gap = value
                ? `${value.value} ${plural(locale.value, value.count, t(value.unit))}`
                : null;
        } else if (previous?.at && !step.at && index === steps.findIndex((s) => !s.at)) {
            const value = age(
                Math.floor((Date.now() - Date.parse(previous.at)) / 1000),
            );

            gap = value
                ? `${value.value} ${plural(locale.value, value.count, t(value.unit))}`
                : null;
            waiting = true;
        }

        return { ...step, gap, waiting, done: step.at !== null };
    });
});

const facts = computed(() => [
    { label: t('install.firstRun'), value: dateTime(props.user.first_seen_at, tag.value) },
    { label: t('install.lastSeen'), value: dateTime(props.user.last_seen_at, tag.value) },
    { label: t('install.platform'), value: props.user.platform ?? '—' },
    { label: t('install.version'), value: props.user.app_version ?? '—' },
    { label: t('install.language'), value: props.user.language ?? '—' },
    { label: t('install.source'), value: props.user.source ?? '—' },
    { label: t('install.campaign'), value: props.user.campaign ?? '—' },
    { label: t('install.referrer'), value: props.user.referrer ?? '—' },
    { label: t('install.landing'), value: props.user.landing_path ?? '—' },
    { label: t('install.sessions'), value: String(props.sessions.length) },
    {
        label: t('install.addresses'),
        value: String(props.user.linked_addresses),
    },
]);

function isMeaningful(event: string): boolean {
    return props.meaningful.includes(event);
}
</script>

<template>
    <Head :title="`Мостик · ${short}`" />

    <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap">
        <Link href="/crm/numbers" class="mk-btn mk-ghost" style="padding: 0 8px"
            >← {{ t('install.back') }}</Link
        >
        <h1 class="mk-h1 mk-m">{{ t('install.title', { short }) }}</h1>
        <span class="mk-m" style="font-size: 11.5px; color: var(--mk-fainter)">{{
            user.id
        }}</span>
        <div style="margin-left: auto; display: flex; gap: 8px">
            <span class="mk-tag"
                >{{ user.platform ?? '—' }} {{ user.app_version ?? '' }}</span
            >
            <span class="mk-tag"
                >{{ user.source ?? '—' }} · {{ user.campaign ?? '—' }}</span
            >
        </div>
    </div>

    <div
        style="
            display: grid;
            grid-template-columns: 300px 300px minmax(0, 1fr);
            gap: 24px;
            flex: 1;
            min-height: 0;
        "
    >
        <div>
            <Rule :label="t('install.whereFrom')" />
            <div style="margin-top: 10px">
                <div
                    v-for="fact in facts"
                    :key="fact.label"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        padding: 8px 0;
                    "
                >
                    <span class="mk-t3" style="font-size: 12px">{{
                        fact.label
                    }}</span>
                    <span
                        class="mk-m mk-clip"
                        style="
                            margin-left: auto;
                            font-size: 12px;
                            color: var(--mk-body);
                        "
                        >{{ fact.value }}</span
                    >
                </div>
            </div>
            <p
                class="mk-t3"
                style="margin: 14px 0 0; font-size: 11px; line-height: 1.55"
            >
                {{ t('install.identityNote') }}
            </p>
        </div>

        <div>
            <Rule :label="t('install.whereStuck')" />
            <div style="margin-top: 14px">
                <template v-for="(step, index) in milestones" :key="step.key">
                    <div
                        v-if="index > 0"
                        style="
                            display: flex;
                            align-items: center;
                            gap: 9px;
                            padding-left: 5px;
                            height: 26px;
                        "
                    >
                        <span
                            style="width: 1px; height: 100%"
                            :style="{
                                background: step.waiting
                                    ? 'rgba(224,165,22,.4)'
                                    : step.done
                                      ? 'rgba(0,229,209,.4)'
                                      : 'rgba(232,236,236,.1)',
                            }"
                        />
                        <span
                            v-if="step.gap"
                            class="mk-k"
                            :style="{
                                color: step.waiting
                                    ? 'var(--mk-warning)'
                                    : 'var(--mk-faint)',
                            }"
                        >
                            {{
                                step.waiting
                                    ? t('install.waiting', { gap: step.gap })
                                    : t('install.after', { gap: step.gap })
                            }}
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px">
                        <span
                            style="
                                width: 11px;
                                height: 11px;
                                border-radius: 999px;
                                border: 2px solid;
                                flex: 0 0 11px;
                            "
                            :style="{
                                borderColor: step.done
                                    ? 'var(--mk-accent)'
                                    : step.waiting
                                      ? 'var(--mk-warning)'
                                      : 'var(--mk-flat)',
                                background: step.done
                                    ? 'var(--mk-accent)'
                                    : 'transparent',
                            }"
                        />
                        <span
                            style="font-size: 13px"
                            :style="{
                                fontWeight: step.done ? 600 : 400,
                                color: step.done
                                    ? 'var(--mk-text)'
                                    : 'var(--mk-fainter)',
                            }"
                            >{{ t(`install.milestone.${step.key}`) }}</span
                        >
                        <span
                            class="mk-m"
                            style="margin-left: auto; font-size: 11.5px"
                            :style="{
                                color: step.done
                                    ? 'var(--mk-dim)'
                                    : 'var(--mk-fainter)',
                            }"
                            >{{ step.at ? dateTime(step.at, tag) : '—' }}</span
                        >
                    </div>
                </template>
            </div>

            <div
                style="
                    margin-top: 22px;
                    border: 1px solid rgba(224, 165, 22, 0.3);
                    background: var(--mk-warning-soft);
                    padding: 12px 14px;
                "
            >
                <p class="mk-k" style="margin: 0; color: var(--mk-warning)">
                    {{ t('install.canDo') }}
                </p>
                <p
                    style="
                        margin: 8px 0 0;
                        font-size: 12px;
                        line-height: 1.55;
                        color: #b79445;
                    "
                >
                    {{ t('install.peers', { count: num(peers.count) }) }}
                </p>
            </div>
        </div>

        <div style="min-width: 0">
            <Rule
                :label="t('install.timeline')"
                :note="t('install.timelineNote')"
            />
            <div style="margin-top: 10px">
                <div
                    v-for="(event, index) in timeline"
                    :key="index"
                    class="mk-hair"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 14px;
                        padding: 9px 2px;
                    "
                >
                    <span
                        class="mk-m mk-t3"
                        style="width: 110px; flex: 0 0 110px; font-size: 11.5px"
                        >{{ dateTime(event.created_at, tag) }}</span
                    >
                    <span
                        class="mk-m mk-clip"
                        style="width: 210px; flex: 0 0 210px; font-size: 12px"
                    >
                        {{ event.event }}
                        <span
                            v-if="isMeaningful(event.event)"
                            class="mk-tag"
                            style="
                                margin-left: 9px;
                                border-color: rgba(0, 229, 209, 0.4);
                                color: var(--mk-accent);
                            "
                            >{{ t('install.milestoneTag') }}</span
                        >
                    </span>
                    <span
                        class="mk-m"
                        style="
                            width: 74px;
                            flex: 0 0 74px;
                            font-size: 11.5px;
                            color: var(--mk-fainter);
                        "
                        >{{ event.chain ?? '—' }}</span
                    >
                </div>
                <p
                    v-if="!timeline.length"
                    class="mk-t3"
                    style="font-size: 12px"
                >
                    {{ t('install.noEvents') }}
                </p>
            </div>
            <p
                class="mk-t3"
                style="margin: 12px 0 0; font-size: 11px; line-height: 1.55"
            >
                {{ t('install.meaningfulNote') }}
            </p>
        </div>
    </div>
</template>
