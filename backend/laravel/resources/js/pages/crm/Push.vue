<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { num, shortDate, shortTime } from '@/lib/console';
import { consoleMessages } from '@/lib/consoleMessages';

type Recipient = {
    id: number;
    name: string;
    locale: string | null;
    devices: number;
};

type Sent = {
    kind: string;
    title: string;
    body: string;
    user_id: number;
    read: boolean;
    at: string;
};

const props = defineProps<{
    health: {
        ok: boolean;
        public: string;
        private: string;
        subject: string;
        problems: string[];
    };
    recipients: Recipient[];
    coverage: { reachable: number; accounts: number; devices: number };
    recent: Sent[];
}>();

const { t, tag } = useLocale(consoleMessages);
const page = usePage();
const showEnglish = ref(false);

const form = useForm({
    audience: 'all' as 'all' | 'user',
    user_id: props.recipients[0]?.id ?? null,
    title: '',
    body: '',
    url: '/profile',
    title_en: '',
    body_en: '',
});

const canSend = computed(
    () =>
        props.health.ok &&
        props.coverage.reachable > 0 &&
        form.title.trim() !== '' &&
        form.body.trim() !== '',
);

const outcome = computed(() => {
    const status = (page.props.flash as { status?: string } | undefined)?.status ?? '';

    if (!status.startsWith('push-sent:')) {
        return null;
    }

    const [, sent, failed] = status.split(':');

    return { sent: Number(sent), failed: Number(failed) };
});

function send(): void {
    form.post('/crm/push', {
        preserveScroll: true,
        onSuccess: () => {
            form.title = '';
            form.body = '';
            form.title_en = '';
            form.body_en = '';
        },
    });
}
</script>

<template>
    <Head title="Пульт · Уведомления" />

    <div style="display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap">
        <h1 class="mk-h1">{{ t('push.title') }}</h1>
        <span class="mk-m mk-t3" style="font-size: 12px">
            {{ t('push.subtitle') }}
        </span>
    </div>

    <!--
      The health strip comes before the composer on purpose. A send button over
      unusable keys is a button that lies, and that is exactly how this feature
      spent its first hours: keys "set", subscription stored, every send dying
      inside the library with nothing on any screen saying so.
    -->
    <div
        class="mk-panel"
        style="margin-top: 18px; padding: 14px 16px"
        :style="
            props.health.ok
                ? { borderColor: 'rgba(0,229,209,.35)' }
                : { borderColor: 'rgba(255,77,77,.45)' }
        "
    >
        <div class="mk-k" :style="{ color: props.health.ok ? 'var(--mk-accent)' : 'var(--mk-critical)' }">
            {{ props.health.ok ? t('push.health.ok') : t('push.health.broken') }}
            <span v-if="props.health.ok" class="mk-m" style="color: var(--mk-dim)">
                {{ props.health.subject }}
            </span>
        </div>
        <ul v-if="!props.health.ok" style="margin: 10px 0 0; padding-left: 18px">
            <li v-for="problem in props.health.problems" :key="problem" style="font-size: 13px">
                {{ problem }}
            </li>
        </ul>
    </div>

    <div class="mk-grid" style="margin-top: 14px">
        <div class="mk-panel" style="padding: 15px">
            <div class="mk-k">{{ t('push.reachable') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(props.coverage.reachable) }}
            </div>
        </div>
        <div class="mk-panel" style="padding: 15px">
            <div class="mk-k">{{ t('push.devices') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(props.coverage.devices) }}
            </div>
        </div>
        <div class="mk-panel" style="padding: 15px">
            <div class="mk-k">{{ t('push.accounts') }}</div>
            <div class="mk-num" style="margin-top: 8px; font-size: 25px">
                {{ num(props.coverage.accounts) }}
            </div>
        </div>
    </div>

    <div v-if="outcome" class="mk-panel" style="margin-top: 14px; padding: 12px 16px; border-color: rgba(0,229,209,.35)">
        <span class="mk-k" style="color: var(--mk-accent)">
            {{ outcome.sent }} → отправлено<span v-if="outcome.failed">, {{ outcome.failed }} не дошло</span>
        </span>
    </div>

    <h2 class="mk-k" style="margin-top: 26px">{{ t('push.compose') }}</h2>

    <div v-if="props.coverage.reachable === 0" class="mk-panel" style="margin-top: 10px; padding: 16px">
        <span class="mk-m mk-t3">{{ t('push.nobody') }}</span>
    </div>

    <form v-else class="mk-panel" style="margin-top: 10px; padding: 16px; display: flex; flex-direction: column; gap: 14px" @submit.prevent="send">
        <label style="display: flex; flex-direction: column; gap: 6px">
            <span class="mk-k">{{ t('push.audience') }}</span>
            <select v-model="form.audience" class="mk-input">
                <option value="all">{{ t('push.audience.all') }} ({{ props.coverage.reachable }})</option>
                <option value="user">{{ t('push.audience.one') }}</option>
            </select>
        </label>

        <label v-if="form.audience === 'user'" style="display: flex; flex-direction: column; gap: 6px">
            <span class="mk-k">—</span>
            <select v-model="form.user_id" class="mk-input">
                <option v-for="person in props.recipients" :key="person.id" :value="person.id">
                    {{ person.name }} · {{ person.locale ?? t('push.no.locale') }} ·
                    {{ person.devices }}
                </option>
            </select>
        </label>

        <label style="display: flex; flex-direction: column; gap: 6px">
            <span class="mk-k">{{ t('push.field.title') }}</span>
            <input v-model="form.title" class="mk-input" maxlength="80" />
        </label>

        <label style="display: flex; flex-direction: column; gap: 6px">
            <span class="mk-k">{{ t('push.field.body') }}</span>
            <textarea v-model="form.body" class="mk-input" rows="3" maxlength="300"></textarea>
        </label>

        <label style="display: flex; flex-direction: column; gap: 6px">
            <span class="mk-k">{{ t('push.field.url') }}</span>
            <input v-model="form.url" class="mk-input" maxlength="200" />
        </label>

        <!--
          Optional, and it defaults to the Russian text rather than to nothing:
          sending Russian words labelled "en" to somebody who asked for English
          is worse than sending them the same words with no claim attached.
        -->
        <div>
            <button type="button" class="mk-btn" @click="showEnglish = !showEnglish">
                {{ t('push.field.en') }}
            </button>
            <div v-if="showEnglish" style="margin-top: 10px; display: flex; flex-direction: column; gap: 10px">
                <input v-model="form.title_en" class="mk-input" maxlength="80" placeholder="Title" />
                <textarea v-model="form.body_en" class="mk-input" rows="3" maxlength="300" placeholder="Text"></textarea>
            </div>
        </div>

        <div v-if="form.errors.title" class="mk-m" style="color: var(--mk-critical); font-size: 13px">
            {{ form.errors.title }}
        </div>

        <div>
            <button type="submit" class="mk-btn mk-act" :disabled="!canSend || form.processing">
                {{ form.processing ? t('push.sending') : t('push.send') }}
            </button>
        </div>
    </form>

    <h2 class="mk-k" style="margin-top: 30px">{{ t('push.recent') }}</h2>

    <div class="mk-scroll-x" style="margin-top: 10px">
        <table class="mk-table">
            <tbody>
                <tr v-for="(row, index) in props.recent" :key="index">
                    <td class="mk-m mk-t3" style="white-space: nowrap">{{ shortDate(row.at, tag) }} {{ shortTime(row.at, tag) }}</td>
                    <td class="mk-m mk-t3" style="white-space: nowrap">#{{ row.user_id }}</td>
                    <td class="mk-m mk-t3" style="white-space: nowrap">{{ row.kind }}</td>
                    <td>
                        <div style="color: var(--mk-text)">{{ row.title }}</div>
                        <div class="mk-m mk-t3" style="font-size: 12px">{{ row.body }}</div>
                    </td>
                    <td class="mk-m mk-t3" style="white-space: nowrap">
                        <span v-if="!row.read" style="color: var(--mk-warning)">{{ t('push.unread') }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
