import { computed, ref, watch } from 'vue';

/**
 * Simple or full: how much of the wallet is on its face.
 *
 * Most people opening the wallet are new to crypto, and the full wallet put
 * thirty places in the rail and fifteen tiles under the balance before they
 * had sent anything. Simple is the default: the balance, receive, send, swap,
 * history and the community, with everything else one tap down behind "More".
 * Full is the same app with every place listed, one switch away in
 * Preferences. Nothing is removed in either mode.
 */
export const WALLET_MODES = ['simple', 'full'] as const;

export type WalletMode = (typeof WALLET_MODES)[number];

const STORAGE_KEY = 'cyberia.wallet.mode';

function isMode(value: unknown): value is WalletMode {
    return WALLET_MODES.includes(value as WalletMode);
}

function stored(): WalletMode {
    if (typeof window === 'undefined') {
        return 'simple';
    }

    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return isMode(value) ? value : 'simple';
    } catch {
        return 'simple';
    }
}

// Module-level, so the rail, the tab bar and Preferences share one value.
const mode = ref<WalletMode>(stored());

const simple = computed(() => mode.value === 'simple');

watch(mode, (next) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // A choice nobody can save still holds for this tab.
    }
});

export function useWalletMode() {
    return { mode, simple };
}
