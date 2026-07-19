<script setup lang="ts">
import { Head, Link as InertiaLink, usePage } from '@inertiajs/vue3';
import { LockKeyhole, Radio, RotateCcw, Send } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import PageHero from '@/components/web3/PageHero.vue';
import PageShell from '@/components/web3/PageShell.vue';
import { useLocale } from '@/composables/useLocale';
import { chat, reset } from '@/routes/lain';
import { login as walletLogin } from '@/routes/wallet';

type ChatMessage = {
    id: number;
    role: 'user' | 'lain';
    text: string;
};

type JsonObject = Record<string, unknown>;

class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
    ) {
        super(message);
    }
}

const props = defineProps<{
    enabled: boolean;
    messages: ChatMessage[];
}>();

const { t, locale, toggleLocale } = useLocale({
    en: {
        title: 'Talk to Lain',
        eyebrow: 'LainOS / personal agent',
        description:
            'Your personal line to the resident mind of Cyberia — an agent built on LainOS. No tools, no transactions: just a conversation.',
        aboutTitle: 'About this line',
        aboutBody:
            'Each user gets their own thread with Lain. She knows the Cyberia ecosystem but holds no keys and runs no transactions from here.',
        savedNote: 'Conversations are stored with your account.',
        newSession: 'New session',
        signalLocked: 'signal locked',
        signInHint: 'Sign in with your wallet to open your personal line.',
        signIn: 'Sign in',
        offline: 'Lain is not wired up on this server yet.',
        placeholder: 'say something to lain…',
        sendLabel: 'Send message',
        genericError: 'Lain did not answer.',
        you: 'you',
    },
    ru: {
        title: 'Поговорить с Лейн',
        eyebrow: 'LainOS / персональный агент',
        description:
            'Твоя личная линия к резидентному разуму Сайберии — агенту на базе LainOS. Без инструментов и транзакций: просто разговор.',
        aboutTitle: 'Об этой линии',
        aboutBody:
            'У каждого пользователя — свой тред с Лейн. Она знает экосистему Сайберии, но не держит ключей и не проводит транзакции отсюда.',
        savedNote: 'Переписка сохраняется в твоём аккаунте.',
        newSession: 'Новая сессия',
        signalLocked: 'сигнал закрыт',
        signInHint: 'Войди через кошелёк, чтобы открыть свою личную линию.',
        signIn: 'Войти',
        offline: 'Лейн ещё не подключена на этом сервере.',
        placeholder: 'скажи что-нибудь лейн…',
        sendLabel: 'Отправить сообщение',
        genericError: 'Лейн не ответила.',
        you: 'ты',
    },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const sending = ref(false);
const resetting = ref(false);
const error = ref<string | null>(null);
const input = ref('');
const messages = ref<ChatMessage[]>([...props.messages]);
const transcript = ref<HTMLElement | null>(null);
let localMessageId = -1;

function csrfToken(): string | null {
    const match = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match
        ? decodeURIComponent(match.split('=').slice(1).join('='))
        : null;
}

async function requestJson<T>(
    url: string,
    options: RequestInit = {},
): Promise<T> {
    const token = csrfToken();
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(options.body && token ? { 'X-XSRF-TOKEN': token } : {}),
            ...(options.headers ?? {}),
        },
    });
    const data = (await response.json().catch(() => ({}))) as JsonObject;

    if (!response.ok) {
        throw new ApiError(
            typeof data.message === 'string'
                ? data.message
                : `Request failed (${response.status})`,
            response.status,
        );
    }

    return data as T;
}

async function scrollToBottom(smooth = true): Promise<void> {
    await nextTick();
    transcript.value?.scrollTo({
        top: transcript.value.scrollHeight,
        behavior: smooth ? 'smooth' : 'auto',
    });
}

async function sendMessage(): Promise<void> {
    const text = input.value.trim();

    if (!text || sending.value || !isAuthenticated.value || !props.enabled) {
        return;
    }

    error.value = null;
    input.value = '';
    const pending: ChatMessage = { id: localMessageId--, role: 'user', text };
    messages.value.push(pending);
    sending.value = true;
    await scrollToBottom();

    try {
        const reply = await requestJson<{ id: number; text: string }>(
            chat().url,
            {
                method: chat().method,
                body: JSON.stringify({ text }),
            },
        );
        messages.value.push({ id: reply.id, role: 'lain', text: reply.text });
    } catch (cause) {
        // The failed turn is not persisted server-side; put the text back so
        // the user can retry it.
        messages.value = messages.value.filter((m) => m !== pending);
        input.value = text;
        error.value =
            cause instanceof Error ? cause.message : t('genericError');
    } finally {
        sending.value = false;
        await scrollToBottom();
    }
}

async function startNewSession(): Promise<void> {
    if (resetting.value || sending.value || messages.value.length === 0) {
        return;
    }

    resetting.value = true;
    error.value = null;

    try {
        await requestJson(reset().url, { method: reset().method });
        messages.value = [];
    } catch (cause) {
        error.value =
            cause instanceof Error ? cause.message : t('genericError');
    } finally {
        resetting.value = false;
    }
}

onMounted(() => scrollToBottom(false));
</script>

<template>
    <Head :title="t('title')" />

    <PageShell size="wide">
        <template #hero>
            <PageHero
                :eyebrow="t('eyebrow')"
                :title="t('title')"
                :description="t('description')"
            >
                <template #actions>
                    <Badge
                        variant="outline"
                        class="gap-1.5 border-brand-cyan/40 text-brand-cyan"
                    >
                        <Radio class="h-3 w-3" />
                        LainOS
                    </Badge>
                    <Button variant="ghost" size="sm" @click="toggleLocale">
                        {{ locale === 'ru' ? 'EN' : 'RU' }}
                    </Button>
                </template>
            </PageHero>
        </template>

        <div class="grid gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <aside class="space-y-4">
                <section
                    class="overflow-hidden rounded-xl border border-border bg-card"
                >
                    <div class="aspect-[16/10] overflow-hidden bg-black">
                        <img
                            src="/token-icons/lain.jpg"
                            alt="Lain"
                            class="h-full w-full object-cover opacity-80 grayscale"
                        />
                    </div>
                    <div class="space-y-4 p-5">
                        <div>
                            <p
                                class="text-xs tracking-widest text-muted-foreground uppercase"
                            >
                                {{ t('aboutTitle') }}
                            </p>
                            <p
                                class="mt-2 text-sm leading-6 text-muted-foreground"
                            >
                                {{ t('aboutBody') }}
                            </p>
                            <p class="mt-2 text-xs text-muted-foreground/70">
                                {{ t('savedNote') }}
                            </p>
                        </div>

                        <Button
                            v-if="isAuthenticated"
                            variant="outline"
                            class="w-full"
                            :disabled="
                                resetting || sending || messages.length === 0
                            "
                            @click="startNewSession"
                        >
                            <RotateCcw class="mr-2 h-4 w-4" />
                            {{ t('newSession') }}
                        </Button>
                    </div>
                </section>
            </aside>

            <section
                class="flex min-h-[620px] flex-col overflow-hidden rounded-xl border border-border bg-[hsl(210_18%_3%)] text-neutral-100 shadow-2xl"
            >
                <header
                    class="flex items-center justify-between border-b border-white/10 px-5 py-3"
                >
                    <div
                        class="flex items-center gap-2 font-mono text-xs tracking-widest uppercase"
                    >
                        <span
                            class="h-2 w-2 rounded-full"
                            :class="
                                isAuthenticated && enabled
                                    ? 'bg-brand-cyan'
                                    : 'bg-neutral-600'
                            "
                        ></span>
                        wired://lain
                    </div>
                    <span class="font-mono text-[10px] text-neutral-500"
                        >chain 49406</span
                    >
                </header>

                <div
                    ref="transcript"
                    class="flex flex-1 flex-col gap-5 overflow-y-auto p-5 sm:p-7"
                    aria-live="polite"
                >
                    <div
                        v-if="!isAuthenticated"
                        class="m-auto max-w-md text-center"
                    >
                        <LockKeyhole class="mx-auto h-9 w-9 text-neutral-600" />
                        <p class="mt-4 font-mono text-sm text-neutral-300">
                            {{ t('signalLocked') }}
                        </p>
                        <p class="mt-2 text-sm leading-6 text-neutral-500">
                            {{ t('signInHint') }}
                        </p>
                        <Button as-child class="mt-5">
                            <InertiaLink :href="walletLogin().url">
                                {{ t('signIn') }}
                            </InertiaLink>
                        </Button>
                    </div>

                    <div
                        v-else-if="!enabled"
                        class="m-auto max-w-md text-center"
                    >
                        <LockKeyhole class="mx-auto h-9 w-9 text-neutral-600" />
                        <p class="mt-4 text-sm leading-6 text-neutral-500">
                            {{ t('offline') }}
                        </p>
                    </div>

                    <template v-else>
                        <article
                            v-for="message in messages"
                            :key="message.id"
                            class="max-w-[85%]"
                            :class="
                                message.role === 'user'
                                    ? 'ml-auto text-right'
                                    : ''
                            "
                        >
                            <p
                                class="mb-1 font-mono text-[10px] tracking-widest uppercase"
                                :class="
                                    message.role === 'lain'
                                        ? 'text-brand-cyan'
                                        : 'text-neutral-500'
                                "
                            >
                                {{ message.role === 'lain' ? 'lain' : t('you') }}
                            </p>
                            <p
                                class="rounded-xl px-4 py-3 text-left text-sm leading-6 whitespace-pre-wrap"
                                :class="
                                    message.role === 'lain'
                                        ? 'border border-white/10 bg-white/5 text-neutral-200'
                                        : 'bg-brand-cyan text-black'
                                "
                            >
                                {{ message.text }}
                            </p>
                        </article>
                    </template>

                    <div
                        v-if="sending"
                        class="font-mono text-xs text-brand-cyan/70"
                    >
                        lain is thinking<span class="animate-pulse">_</span>
                    </div>
                </div>

                <div
                    v-if="error"
                    class="border-t border-red-500/20 bg-red-500/5 px-5 py-2 text-xs text-red-300"
                >
                    {{ error }}
                </div>

                <form
                    class="flex items-end gap-3 border-t border-white/10 p-4"
                    @submit.prevent="sendMessage"
                >
                    <textarea
                        v-model="input"
                        rows="2"
                        maxlength="2000"
                        :disabled="!isAuthenticated || !enabled || sending"
                        :placeholder="t('placeholder')"
                        class="min-h-12 flex-1 resize-none rounded-lg border border-white/10 bg-white/5 px-4 py-3 font-mono text-sm text-neutral-100 outline-none placeholder:text-neutral-600 focus:border-brand-cyan/50 disabled:cursor-not-allowed disabled:opacity-50"
                        @keydown.enter.exact.prevent="sendMessage"
                    ></textarea>
                    <Button
                        type="submit"
                        size="icon"
                        class="h-12 w-12 shrink-0"
                        :disabled="
                            !isAuthenticated ||
                            !enabled ||
                            sending ||
                            !input.trim()
                        "
                        :aria-label="t('sendLabel')"
                    >
                        <Send class="h-4 w-4" />
                    </Button>
                </form>
            </section>
        </div>
    </PageShell>
</template>
