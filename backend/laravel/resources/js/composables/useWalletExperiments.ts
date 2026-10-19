import { computed, ref, watch } from 'vue';
import { EXPERIMENTS } from '@/lib/wallet/layers';
import type { ExperimentId } from '@/lib/wallet/layers';

/**
 * The switches on layer 03, and whether each one is on.
 *
 * Local to this device, like the theme: they are about how this screen looks
 * and nothing else. Whether a switch *may* be turned on is the layer's
 * question (`EXPERIMENTS[].requires`); once it is on it stays on, because
 * taking a look away from somebody who signed out would be punishing the
 * wrong thing.
 */
const STORAGE_KEY = 'cyberia.wallet.experiments';

const KNOWN: readonly string[] = EXPERIMENTS.map((entry) => entry.id);

function stored(): ExperimentId[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const value = JSON.parse(
            window.localStorage.getItem(STORAGE_KEY) ?? '[]',
        ) as unknown;

        return Array.isArray(value)
            ? (value.filter((id) => KNOWN.includes(id)) as ExperimentId[])
            : [];
    } catch {
        return [];
    }
}

// Module-level, so the layer screen and the overlay share one value.
const enabled = ref<ExperimentId[]>(stored());

watch(enabled, (next) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch {
        // A choice nobody can save still holds for this tab.
    }
});

const isOn = (id: ExperimentId): boolean => enabled.value.includes(id);

const toggle = (id: ExperimentId, on: boolean): void => {
    enabled.value = on
        ? [...new Set([...enabled.value, id])]
        : enabled.value.filter((entry) => entry !== id);
};

const anyOn = computed(() => enabled.value.length > 0);

export function useWalletExperiments() {
    return { enabled, isOn, toggle, anyOn };
}
