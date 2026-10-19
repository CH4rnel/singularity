<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { RefreshCw } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { shortAddress } from '@/lib/wallet/format';
import { signInWithWallet } from '@/lib/wallet/session';
import { dropBackup, readBackup, storeBackup } from '@/lib/wallet/sync';
import type { SyncBackup } from '@/lib/wallet/sync';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Sync: the same wallet on the site, in Telegram and in the apps.
 *
 * The profile keeps this wallet sealed under a sync password the person
 * chooses once; the server holds ciphertext only. On another device — or
 * inside Telegram, where the profile signs in by itself — the same password
 * opens it there.
 *
 * `panel` is the full control (Preferences). `card` is the home screen's
 * offer, shown only when there is something to do: the profile holds a
 * different wallet than this device, or this wallet has money and isn't kept
 * anywhere yet.
 */

const props = withDefaults(
    defineProps<{
        wallet: MultiWallet;
        authenticated: boolean;
        variant?: 'card' | 'panel';
        /** Whether this device's wallet holds anything (the card's cue). */
        funded?: boolean;
    }>(),
    { variant: 'panel', funded: false },
);

const emit = defineEmits<{
    security: [];
    /** This device now holds the profile's wallet: read its balances. */
    restored: [];
}>();

const { t } = useLocale(walletMessages);

const backup = ref<SyncBackup | null>(null);
const loaded = ref(false);
const busy = ref(false);
const error = ref<string | null>(null);
const password = ref('');
const repeat = ref('');
const confirmReplace = ref(false);
const opened = ref(false);
/** Keep this device's wallet in the profile instead of the one there. */
const replacing = ref(false);

const localAddress = computed(() =>
    props.wallet.unlocked.value ? props.wallet.syncAddress() : null,
);

const state = computed<'signedOut' | 'off' | 'on' | 'other' | 'loading'>(() => {
    if (!props.authenticated) {
        return 'signedOut';
    }

    if (!loaded.value) {
        return 'loading';
    }

    if (backup.value === null) {
        return 'off';
    }

    return backup.value.address.toLowerCase() ===
        localAddress.value?.toLowerCase()
        ? 'on'
        : 'other';
});

const shown = computed(() => {
    if (props.variant === 'panel') {
        return true;
    }

    return (
        state.value === 'other' ||
        (state.value === 'off' && props.funded) ||
        opened.value
    );
});

const load = async (): Promise<void> => {
    if (!props.authenticated) {
        return;
    }

    try {
        backup.value = await readBackup();
        loaded.value = true;
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
    }
};

onMounted(load);
watch(() => props.authenticated, load);

const run = async (task: () => Promise<void>): Promise<void> => {
    if (busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        await task();
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
    } finally {
        busy.value = false;
    }
};

const signIn = (): Promise<void> =>
    run(async () => {
        const address = localAddress.value;

        if (!address) {
            return;
        }

        await signInWithWallet(address, (message) =>
            props.wallet.signMessage('cyberia', message),
        );
        router.reload({ only: ['auth'] });
    });

const enable = (replace = false): Promise<void> =>
    run(async () => {
        if (password.value.length < 8) {
            throw new Error(t('syncPasswordShort'));
        }

        if (password.value !== repeat.value) {
            throw new Error(t('syncPasswordMismatch'));
        }

        const address = localAddress.value;

        if (!address) {
            return;
        }

        const record = await props.wallet.sealForProfile(password.value);

        await storeBackup(address, record, replace);
        password.value = '';
        repeat.value = '';
        confirmReplace.value = false;
        replacing.value = false;
        await load();
    });

const restore = (): Promise<void> =>
    run(async () => {
        if (!backup.value) {
            return;
        }

        if (props.funded && !confirmReplace.value) {
            confirmReplace.value = true;

            return;
        }

        try {
            await props.wallet.restoreFromProfile(
                backup.value.payload,
                password.value,
            );
        } catch {
            throw new Error(t('syncWrongPassword'));
        }

        password.value = '';
        confirmReplace.value = false;
        opened.value = true;
        emit('restored');
    });

const disable = (): Promise<void> =>
    run(async () => {
        await dropBackup();
        backup.value = null;
    });
</script>

<template>
    <div
        v-if="shown"
        class="cw-card"
        :style="
            variant === 'card'
                ? 'display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px'
                : 'display: flex; flex-direction: column; gap: 10px'
        "
    >
        <div class="cw-row" style="align-items: flex-start; gap: 10px">
            <RefreshCw
                :size="18"
                aria-hidden="true"
                style="flex: none; margin-top: 2px; color: var(--cw-accent)"
            />
            <span style="flex: 1; min-width: 0">
                <strong class="cw-asset-name">{{ t('syncTitle') }}</strong>
                <span class="cw-hint" style="margin-top: 4px">
                    <template v-if="opened">{{ t('syncRestored') }}</template>
                    <template v-else-if="state === 'on'">{{
                        t('syncOn', {
                            address: shortAddress(backup!.address, 6, 4),
                        })
                    }}</template>
                    <template v-else-if="state === 'other'">{{
                        t('syncOther', {
                            address: shortAddress(backup!.address, 6, 4),
                        })
                    }}</template>
                    <template v-else>{{ t('syncPitch') }}</template>
                </span>
            </span>
        </div>

        <button
            v-if="state === 'signedOut'"
            type="button"
            class="cw-btn cw-btn-secondary"
            style="height: 44px"
            :disabled="busy"
            @click="signIn"
        >
            {{ busy ? '…' : t('syncSignIn') }}
        </button>

        <template v-else-if="state === 'off' || replacing">
            <input
                v-model="password"
                type="password"
                class="cw-input"
                autocomplete="new-password"
                :placeholder="t('syncPasswordNew')"
                :aria-label="t('syncPasswordNew')"
            />
            <input
                v-model="repeat"
                type="password"
                class="cw-input"
                autocomplete="new-password"
                :placeholder="t('syncPasswordRepeat')"
                :aria-label="t('syncPasswordRepeat')"
            />
            <span class="cw-hint">{{ t('syncPasswordHint') }}</span>
            <button
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 44px"
                :disabled="busy"
                @click="enable(replacing)"
            >
                {{ busy ? '…' : t('syncEnable') }}
            </button>
        </template>

        <template v-else-if="state === 'other' && !opened && !replacing">
            <input
                v-model="password"
                type="password"
                class="cw-input"
                autocomplete="current-password"
                :placeholder="t('syncPassword')"
                :aria-label="t('syncPassword')"
            />
            <span
                v-if="confirmReplace"
                class="cw-hint"
                style="color: var(--cw-pending)"
            >
                {{
                    t('syncReplaceWarning', {
                        address: shortAddress(localAddress ?? '', 6, 4),
                    })
                }}
                <button
                    type="button"
                    class="cw-back"
                    style="min-height: 0; color: var(--cw-accent)"
                    @click="emit('security')"
                >
                    {{ t('syncShowPhrase') }}
                </button>
            </span>
            <button
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 44px"
                :disabled="busy || password === ''"
                @click="restore"
            >
                {{
                    busy
                        ? '…'
                        : confirmReplace
                          ? t('syncRestoreConfirm')
                          : t('syncRestore')
                }}
            </button>
            <button
                v-if="variant === 'panel'"
                type="button"
                class="cw-ghost"
                style="align-self: flex-start"
                @click="replacing = true"
            >
                {{ t('syncKeepThis') }}
            </button>
        </template>

        <button
            v-if="state === 'on' && variant === 'panel'"
            type="button"
            class="cw-ghost"
            style="align-self: flex-start"
            :disabled="busy"
            @click="disable"
        >
            {{ t('syncDisable') }}
        </button>

        <span v-if="error" class="cw-hint" style="color: var(--cw-bad-soft)">{{
            error
        }}</span>
    </div>
</template>
