<script setup lang="ts">
import { Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { telegramOpenChat } from '@/lib/telegram';
import { walletMessages } from '@/lib/walletMessages';

/**
 * "Notifications in Telegram", in one tap.
 *
 * The wallet signs a challenge naming its Cyberia address (it moves nothing),
 * Laravel answers with a one-time link into the bot, and opening it in
 * Telegram links that account — after which the bot writes when money
 * arrives, when a bridge delivers and when a streak is about to end.
 *
 * `card` is the dismissible offer on the home screen; `row` is the same action
 * on Daily and in Preferences, where it is never hidden.
 */

const props = withDefaults(
    defineProps<{
        wallet: MultiWallet;
        variant?: 'card' | 'row';
    }>(),
    { variant: 'row' },
);

const { t } = useLocale(walletMessages);

const STORAGE_KEY = 'cyberia.wallet.telegramOffer';

const readFlag = (): string | null => {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
};

const writeFlag = (value: string): void => {
    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // The offer comes back next visit; nothing else depends on it.
    }
};

/** `linked` after a link was opened, `dismissed` after ×; null otherwise. */
const flag = ref(typeof window === 'undefined' ? null : readFlag());
const busy = ref(false);
const error = ref<string | null>(null);
const opened = ref<string | null>(null);

const address = computed(
    () =>
        props.wallet.accounts.value.find(
            (account) => account.chain === 'cyberia',
        )?.address ?? null,
);

// The card stays up after the tap, to say what happens next in Telegram.
const shown = computed(
    () =>
        address.value !== null &&
        (props.variant === 'row' ||
            flag.value === null ||
            opened.value !== null),
);

const post = async <T,>(url: string, body: unknown): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });
    const data = (await response.json().catch(() => ({}))) as {
        message?: string;
    } & Record<string, unknown>;

    if (!response.ok) {
        throw new Error(data.message ?? t('telegramLinkFailed'));
    }

    return data as T;
};

const connect = async (): Promise<void> => {
    if (!address.value || busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const { message } = await post<{ message: string }>(
            '/api/wallet/telegram/nonce',
            { address: address.value },
        );
        const signature = await props.wallet.signMessage('cyberia', message);
        const { url } = await post<{ url: string }>(
            '/api/wallet/telegram/link',
            { address: address.value, signature },
        );

        opened.value = url;
        flag.value = 'linked';
        writeFlag('linked');
        telegramOpenChat(url);
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
    } finally {
        busy.value = false;
    }
};

const dismiss = (): void => {
    flag.value = 'dismissed';
    writeFlag('dismissed');
};
</script>

<template>
    <div v-if="shown && variant === 'card'" class="cw-card cw-telegram-card">
        <div class="cw-row" style="align-items: flex-start; gap: 10px">
            <Send
                :size="18"
                aria-hidden="true"
                style="flex: none; margin-top: 2px; color: var(--cw-accent)"
            />
            <span style="flex: 1; min-width: 0">
                <strong class="cw-asset-name">{{
                    t('telegramOfferTitle')
                }}</strong>
                <span class="cw-hint" style="margin-top: 4px">{{
                    t('telegramOfferBody')
                }}</span>
            </span>
            <button
                v-if="!opened"
                type="button"
                class="cw-note-close"
                :title="t('telegramDismiss')"
                :aria-label="t('telegramDismiss')"
                @click="dismiss"
            >
                ×
            </button>
        </div>
        <button
            v-if="!opened"
            type="button"
            class="cw-btn cw-btn-secondary"
            style="height: 44px; margin-top: 12px"
            :disabled="busy"
            @click="connect"
        >
            {{ busy ? '…' : t('telegramConnect') }}
        </button>
        <span v-else class="cw-hint" style="margin-top: 12px">
            {{ t('telegramOpened') }}
            <a
                :href="opened"
                target="_blank"
                rel="noopener noreferrer"
                style="color: var(--cw-accent)"
                >{{ t('telegramOpenAgain') }}</a
            >
        </span>
        <span
            v-if="error"
            class="cw-hint"
            style="margin-top: 8px; color: var(--cw-bad-soft)"
            >{{ error }}</span
        >
    </div>

    <div
        v-else-if="shown"
        style="display: flex; flex-direction: column; gap: 8px"
    >
        <button
            type="button"
            class="cw-ghost"
            style="align-self: flex-start"
            :disabled="busy"
            @click="connect"
        >
            <Send :size="14" aria-hidden="true" />
            {{ busy ? '…' : t('telegramOfferTitle') }}
        </button>
        <span v-if="opened" class="cw-hint">
            {{ t('telegramOpened') }}
            <a
                :href="opened"
                target="_blank"
                rel="noopener noreferrer"
                style="color: var(--cw-accent)"
                >{{ t('telegramOpenAgain') }}</a
            >
        </span>
        <span v-if="error" class="cw-hint" style="color: var(--cw-bad-soft)">{{
            error
        }}</span>
    </div>
</template>
