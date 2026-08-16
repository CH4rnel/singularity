<script setup lang="ts">
import { Loader2, Send, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import HoldButton from '@/components/wallet/HoldButton.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import {
    LAIN_CHAT_CONTEXT,
    clearLainChat,
    formatUnits,
    readLainChat,
    writeLainChat,
} from '@/lib/wallet';
import type { LainTurn } from '@/lib/wallet';
import { growComposer } from '@/lib/wallet/composer';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The $LAIN holders' room.
 *
 * Membership is a balance, not an account: this wallet holds the key to a
 * Cyberia address, and holding enough of the live $LAIN supply is the whole
 * test. The share is read here, from the chain, before the server is told
 * anything — so a wallet that does not qualify never sends its address
 * anywhere. Only when someone chooses to open the room does it sign a
 * challenge, and only then does Cyberia learn which address is talking.
 *
 * Signing here spends nothing and approves nothing. It is the one place in
 * this wallet where a key is used without a transaction, so it still goes
 * through hold-to-confirm and still says what is being signed.
 *
 * The transcript never leaves the device (lib/wallet/lainChat.ts); the server
 * is handed the tail of it as context for one answer and keeps none of it.
 */

const props = defineProps<{
    wallet: MultiWallet;
    config: {
        enabled: boolean;
        tokenAddress: string;
        minimumShareBps: number;
    };
}>();

const { t } = useLocale(walletMessages);

/** $LAIN is a Cyberia ERC20, so the room is gated on the Cyberia account. */
const CHAIN = 'cyberia' as const;

type Holding = {
    balance: bigint;
    supply: bigint;
    decimals: number;
    symbol: string;
};

const holding = ref<Holding | null>(null);
const reading = ref(false);
const readError = ref(false);
const proven = ref(false);
const unlocking = ref(false);
const sending = ref(false);
const error = ref<string | null>(null);
const draft = ref('');
const turns = ref<LainTurn[]>([]);
const transcript = ref<HTMLElement | null>(null);

const address = computed(
    () =>
        props.wallet.accounts.value.find((account) => account.chain === CHAIN)
            ?.address ?? null,
);

/** Basis points of the live supply this account holds, or null while unread. */
const shareBps = computed(() => {
    if (holding.value === null || holding.value.supply === 0n) {
        return null;
    }

    return Number((holding.value.balance * 10_000n) / holding.value.supply);
});

const qualifies = computed(
    () =>
        shareBps.value !== null &&
        shareBps.value >= props.config.minimumShareBps,
);

const stage = computed<
    'off' | 'reading' | 'error' | 'short' | 'locked' | 'open'
>(() => {
    if (!props.config.enabled || !props.config.tokenAddress) {
        return 'off';
    }

    if (readError.value) {
        return 'error';
    }

    if (holding.value === null) {
        return 'reading';
    }

    if (!qualifies.value) {
        return 'short';
    }

    return proven.value ? 'open' : 'locked';
});

const percent = (bps: number): string =>
    (bps / 100).toFixed(2).replace(/\.?0+$/, '');

const requiredPercent = computed(() => percent(props.config.minimumShareBps));

const sharePercent = computed(() =>
    shareBps.value === null ? '—' : `${percent(shareBps.value)}%`,
);

const amount = computed(() =>
    holding.value === null
        ? '—'
        : formatUnits(holding.value.balance, holding.value.decimals, 4),
);

/** How much of the supply the threshold actually is, in tokens. */
const requiredAmount = computed(() => {
    if (holding.value === null) {
        return '—';
    }

    const needed =
        (holding.value.supply * BigInt(props.config.minimumShareBps)) / 10_000n;

    return formatUnits(needed, holding.value.decimals, 4);
});

const symbol = computed(() => holding.value?.symbol ?? 'LAIN');

const csrfToken = (): string => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const post = async <T,>(url: string, body: unknown): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });
    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
    } & Record<string, unknown>;

    if (!response.ok) {
        const failure = new Error(data.message ?? t('lainUnreachable'));
        (failure as Error & { status: number }).status = response.status;

        throw failure;
    }

    return data as T;
};

/**
 * Read the holding straight from the contract. Two calls: what this account
 * has, and what exists — a share cannot be computed from either alone, and a
 * hard-coded supply would drift the moment any is minted or burned.
 */
const readHolding = async (): Promise<void> => {
    if (!props.config.tokenAddress || !address.value || reading.value) {
        return;
    }

    reading.value = true;
    readError.value = false;

    try {
        const [token, supply] = await Promise.all([
            props.wallet.readToken(CHAIN, props.config.tokenAddress),
            props.wallet.readTokenSupply(CHAIN, props.config.tokenAddress),
        ]);

        if (token === null || supply === null) {
            throw new Error('LAIN is unreadable here');
        }

        holding.value = {
            balance: token.balance,
            supply,
            decimals: token.decimals,
            symbol: token.symbol,
        };
    } catch {
        holding.value = null;
        readError.value = true;
    } finally {
        reading.value = false;
    }
};

const scrollDown = async (): Promise<void> => {
    await nextTick();
    transcript.value?.scrollTo({
        top: transcript.value.scrollHeight,
        behavior: 'smooth',
    });
};

/** Prove the address, so the server will answer for it. */
const unlock = async (): Promise<void> => {
    if (!address.value || unlocking.value) {
        return;
    }

    unlocking.value = true;
    error.value = null;

    try {
        // The server composes the exact text that will be verified, so there
        // is no second copy of the format here to drift out of step with it.
        const { message } = await post<{ message: string }>(
            '/api/wallet/lain/nonce',
            { address: address.value },
        );
        const signature = await props.wallet.signMessage(CHAIN, message);

        await post('/api/wallet/lain/verify', {
            address: address.value,
            signature,
        });

        proven.value = true;
        await scrollDown();
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
        // A refusal is usually about the balance, and the number on screen is
        // the one the user will argue with — so re-read it rather than keep it.
        await readHolding();
    } finally {
        unlocking.value = false;
    }
};

const remember = (): void => {
    if (address.value) {
        writeLainChat(address.value, turns.value);
    }
};

const send = async (): Promise<void> => {
    const text = draft.value.trim();

    if (text === '' || sending.value || !address.value) {
        return;
    }

    // The context is the conversation as it stood before this line, because
    // the server appends this line itself as the closing user turn.
    const history = turns.value.slice(-LAIN_CHAT_CONTEXT);

    sending.value = true;
    error.value = null;
    draft.value = '';
    turns.value = [...turns.value, { role: 'user', text }];
    remember();
    await scrollDown();

    try {
        const reply = await post<{ text: string }>('/api/wallet/lain/chat', {
            text,
            history,
        });

        turns.value = [...turns.value, { role: 'lain', text: reply.text }];
        remember();
        await scrollDown();
    } catch (failure) {
        const status = (failure as Error & { status?: number }).status;

        if (status === 403) {
            // Either the proof aged out or the balance moved. Both mean the
            // room is shut until it is re-earned, so show it shut.
            proven.value = false;
            await readHolding();
        }

        error.value =
            failure instanceof Error ? failure.message : String(failure);
    } finally {
        sending.value = false;
    }
};

const forget = (): void => {
    if (address.value) {
        clearLainChat(address.value);
    }

    turns.value = [];
    error.value = null;
};

/** The field itself, so the draft can size it. */
const composer = ref<HTMLTextAreaElement | null>(null);

/** Enter sends; Shift+Enter is a newline, as in every chat ever written. */
const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void send();
    }
};

// A sent message empties the field, and an empty field is one line tall again.
watch(draft, () => void nextTick(() => growComposer(composer.value)));

watch(
    address,
    (next) => {
        // A different account is a different room: its proof, its balance and
        // its conversation are all somebody else's.
        proven.value = false;
        holding.value = null;
        turns.value = next ? readLainChat(next) : [];
        void readHolding();
    },
    { immediate: true },
);

onMounted(() => {
    void scrollDown();
});
</script>

<template>
    <div class="cw-screen">
        <h2 class="cw-title" style="margin: 6px 0 6px; font-size: 22px">
            {{ t('lainTitle') }}
        </h2>
        <p class="cw-prose" style="margin-bottom: 20px; max-width: 62ch">
            {{ t('lainIntro', { required: requiredPercent }) }}
        </p>

        <!-- Membership card: the numbers the gate is actually decided on. -->
        <div
            v-if="stage !== 'off'"
            class="cw-card"
            style="margin-bottom: 16px; padding: 14px 16px"
        >
            <div class="cw-row" style="gap: 16px; flex-wrap: wrap">
                <div>
                    <div class="cw-label">{{ t('lainHolding') }}</div>
                    <div class="cw-num" style="margin-top: 4px">
                        {{ amount }} {{ symbol }}
                    </div>
                </div>
                <div>
                    <div class="cw-label">{{ t('lainShare') }}</div>
                    <div
                        class="cw-num"
                        style="margin-top: 4px"
                        :style="{
                            color: qualifies
                                ? 'var(--cw-ok)'
                                : 'var(--cw-text)',
                        }"
                    >
                        {{ sharePercent }}
                    </div>
                </div>
                <div>
                    <div class="cw-label">{{ t('lainRequired') }}</div>
                    <div class="cw-num" style="margin-top: 4px">
                        {{ requiredPercent }}% · {{ requiredAmount }}
                        {{ symbol }}
                    </div>
                </div>
                <button
                    type="button"
                    class="cw-back"
                    style="margin-left: auto"
                    :disabled="reading"
                    @click="readHolding()"
                >
                    {{ reading ? t('lainReading') : t('refresh') }}
                </button>
            </div>
        </div>

        <p v-if="error" class="cw-note cw-note-bad" style="margin-bottom: 14px">
            <span>{{ error }}</span>
        </p>

        <!-- Off: no model wired up on this server. -->
        <p v-if="stage === 'off'" class="cw-note">
            <span>{{ t('lainOff') }}</span>
        </p>

        <!-- The contract could not be read; this is not "you hold nothing". -->
        <div v-else-if="stage === 'error'" class="cw-stack">
            <p class="cw-note cw-note-warn">
                <span>{{ t('lainReadFailed') }}</span>
            </p>
            <button
                type="button"
                class="cw-btn cw-btn-secondary"
                style="align-self: flex-start"
                @click="readHolding()"
            >
                {{ t('retry') }}
            </button>
        </div>

        <p v-else-if="stage === 'reading'" class="cw-prose">
            {{ t('lainReading') }}
        </p>

        <!-- Below the threshold. Say by how much, not just "no". -->
        <div v-else-if="stage === 'short'" class="cw-stack">
            <p class="cw-note cw-note-warn">
                <span>{{
                    t('lainShort', {
                        required: requiredPercent,
                        share: sharePercent,
                        amount,
                        symbol,
                    })
                }}</span>
            </p>
            <p class="cw-prose" style="max-width: 62ch">
                {{ t('lainShortHint') }}
            </p>
        </div>

        <!-- Qualifies, but the server has not been shown a signature yet. -->
        <div v-else-if="stage === 'locked'" class="cw-stack" style="gap: 14px">
            <div class="cw-card" style="padding: 18px">
                <div
                    style="
                        display: flex;
                        align-items: center;
                        gap: 9px;
                        margin-bottom: 8px;
                    "
                >
                    <ShieldCheck :size="15" aria-hidden="true" />
                    <span class="cw-data">{{ t('lainQualifies') }}</span>
                </div>
                <p class="cw-prose" style="max-width: 62ch">
                    {{ t('lainSignBody') }}
                </p>
                <div style="margin-top: 16px; max-width: 320px">
                    <HoldButton
                        :label="unlocking ? t('lainSigning') : t('lainSign')"
                        :disabled="unlocking"
                        @complete="unlock()"
                    />
                </div>
            </div>
            <p class="cw-prose" style="max-width: 62ch">
                {{ t('lainNoTools') }}
            </p>
        </div>

        <!-- Open. -->
        <div v-else class="cw-chat">
            <div ref="transcript" class="cw-chat-log">
                <p v-if="turns.length === 0" class="cw-prose">
                    {{ t('lainEmpty') }}
                </p>

                <div
                    v-for="(turn, index) in turns"
                    :key="index"
                    class="cw-turn"
                    :class="
                        turn.role === 'lain' ? 'cw-turn-lain' : 'cw-turn-you'
                    "
                >
                    <div class="cw-label" style="margin-bottom: 5px">
                        {{
                            turn.role === 'lain' ? t('lainName') : t('lainYou')
                        }}
                    </div>
                    <div class="cw-turn-text">{{ turn.text }}</div>
                </div>

                <div
                    v-if="sending"
                    class="cw-turn cw-turn-lain"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        color: var(--cw-dim);
                    "
                >
                    <Loader2 :size="13" class="cw-spin" aria-hidden="true" />
                    <span class="cw-label">{{ t('lainThinking') }}</span>
                </div>
            </div>

            <form class="cw-chat-form" @submit.prevent="send()">
                <textarea
                    ref="composer"
                    v-model="draft"
                    class="cw-textarea"
                    rows="1"
                    maxlength="12000"
                    :placeholder="t('lainPlaceholder')"
                    :aria-label="t('lainPlaceholder')"
                    @keydown="onKeydown"
                />
                <!-- Square, like the wire's: the field is what needs width. -->
                <button
                    type="submit"
                    class="cw-btn cw-btn-primary cw-chat-send"
                    :disabled="sending || draft.trim() === ''"
                    :title="t('lainSend')"
                    :aria-label="t('lainSend')"
                >
                    <Loader2
                        v-if="sending"
                        :size="15"
                        class="cw-spin"
                        aria-hidden="true"
                    />
                    <Send v-else :size="15" aria-hidden="true" />
                </button>
            </form>

            <div class="cw-row" style="margin-top: 10px; gap: 12px">
                <span class="cw-label" style="color: var(--cw-faint)">{{
                    t('lainStored')
                }}</span>
                <button
                    v-if="turns.length > 0"
                    type="button"
                    class="cw-back"
                    style="margin-left: auto"
                    @click="forget()"
                >
                    <Trash2 :size="12" aria-hidden="true" />
                    {{ t('lainForget') }}
                </button>
            </div>
        </div>
    </div>
</template>
