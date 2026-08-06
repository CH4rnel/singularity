<script setup lang="ts">
import { Lock } from 'lucide-vue-next';
import { ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The sealed vault. Nothing on this screen reveals anything about the wallet
 * behind it — no address, no balance, no hint of which networks hold what.
 * Forgetting the password is a dead end by design, so the way out is stated
 * plainly: restore from the seed phrase.
 */

const props = defineProps<{ wallet: MultiWallet }>();

const emit = defineEmits<{ restore: [] }>();

const { t } = useLocale(walletMessages);

const password = ref('');
const error = ref<string | null>(null);

const unlock = async (): Promise<void> => {
    error.value = null;

    try {
        await props.wallet.unlock(password.value);
        await props.wallet.refreshBalances();
    } catch {
        error.value = t('wrongPassword');
    } finally {
        password.value = '';
    }
};
</script>

<template>
    <form
        class="cw-stack cw-screen"
        style="justify-content: center; padding: 24px 0"
        @submit.prevent="unlock"
    >
        <div class="cw-stack" style="align-items: center; text-align: center">
            <span
                style="
                    display: flex;
                    width: 52px;
                    height: 52px;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: 22px;
                    border: 1px solid var(--cw-border);
                    color: var(--cw-muted);
                "
            >
                <Lock :size="18" aria-hidden="true" />
            </span>
            <div class="cw-label" style="margin-bottom: 12px">
                {{ t('vaultLocked') }}
            </div>
            <h2 class="cw-title" style="font-size: 22px">
                {{ t('enterPassword') }}
            </h2>
        </div>

        <input
            v-model="password"
            type="password"
            class="cw-input"
            autocomplete="current-password"
            :aria-label="t('password')"
            placeholder="••••••••••••"
            style="height: 52px; margin-top: 26px; text-align: center"
        />
        <button
            type="submit"
            class="cw-btn cw-btn-primary"
            style="margin-top: 10px"
            :disabled="password.length === 0 || wallet.busy.value"
        >
            {{ t('unlock') }}
        </button>

        <p
            v-if="error"
            class="cw-note cw-note-bad"
            style="margin-top: 16px; justify-content: center"
        >
            <span>{{ error }}</span>
        </p>

        <button
            type="button"
            class="cw-ghost"
            style="margin-top: 16px; border: none"
            @click="emit('restore')"
        >
            {{ t('forgotPassword') }}
        </button>
    </form>
</template>
