<script setup lang="ts">
import {
    Check,
    CircleDashed,
    ExternalLink,
    Loader2,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import type { BridgeDirection } from '@/lib/addressValidation';
import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';
import AddressDisplay from './AddressDisplay.vue';

const props = defineProps<{
    direction: BridgeDirection;
    token: BridgeTokenSymbol;
    bridgeRequestId: number;
    sourceTxHash: string;
    destinationAddress: string;
}>();

const emit = defineEmits<{
    (e: 'succeeded', destinationTxHash: string | null): void;
    (e: 'failed', error: string): void;
    (e: 'reset'): void;
}>();

const status = ref<string>('pending');
const destinationTxHash = ref<string | null>(null);
const errorMessage = ref<string | null>(null);
const pollTimer = ref<ReturnType<typeof setTimeout> | null>(null);

const sourceExplorer = computed(() =>
    props.direction === 'sol_to_evm'
        ? `https://solscan.io/tx/${props.sourceTxHash}`
        : `https://explorer.cyberia.church/tx/${props.sourceTxHash}`,
);

const destExplorer = computed(() => {
    if (!destinationTxHash.value) {
        return null;
    }

    return props.direction === 'sol_to_evm'
        ? `https://explorer.cyberia.church/tx/${destinationTxHash.value}`
        : `https://solscan.io/tx/${destinationTxHash.value}`;
});

const poll = async () => {
    try {
        const res = await fetch(`/api/bridge/${props.bridgeRequestId}/status`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!res.ok) {
            return;
        }

        const data = await res.json();

        status.value = data.status;
        destinationTxHash.value = data.destination_tx_hash;
        errorMessage.value = data.error_message;

        if (data.status === 'completed') {
            emit('succeeded', data.destination_tx_hash);

            return;
        }

        if (data.status === 'failed') {
            emit('failed', data.error_message ?? 'Bridge failed');

            return;
        }

        pollTimer.value = setTimeout(poll, 3000);
    } catch {
        pollTimer.value = setTimeout(poll, 5000);
    }
};

onMounted(() => {
    poll();
});

onBeforeUnmount(() => {
    if (pollTimer.value) {
        clearTimeout(pollTimer.value);
    }
});

const steps = computed(() => [
    {
        label: 'Source transaction confirmed',
        state: 'done',
    },
    {
        label: 'Relayer processing',
        state:
            status.value === 'completed'
                ? 'done'
                : status.value === 'failed'
                  ? 'failed'
                  : 'pending',
    },
    {
        label: 'Destination transaction confirmed',
        state:
            status.value === 'completed'
                ? 'done'
                : status.value === 'failed'
                  ? 'failed'
                  : 'idle',
    },
]);
</script>

<template>
    <div class="flex w-full flex-col gap-4">
        <h2 class="text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
            {{
                status === 'completed'
                    ? 'Bridge complete'
                    : status === 'failed'
                      ? 'Bridge failed'
                      : 'Bridging your funds…'
            }}
        </h2>

        <div
            class="flex flex-col gap-3 rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]"
        >
            <div
                v-for="(s, i) in steps"
                :key="i"
                class="flex items-center gap-3"
            >
                <Check
                    v-if="s.state === 'done'"
                    class="h-5 w-5 text-green-500"
                />
                <XCircle
                    v-else-if="s.state === 'failed'"
                    class="h-5 w-5 text-red-500"
                />
                <Loader2
                    v-else-if="s.state === 'pending'"
                    class="h-5 w-5 animate-spin text-yellow-500"
                />
                <CircleDashed
                    v-else
                    class="h-5 w-5 text-[#706f6c] dark:text-[#A1A09A]"
                />
                <span
                    class="text-sm"
                    :class="
                        s.state === 'done'
                            ? 'text-[#1b1b18] dark:text-[#EDEDEC]'
                            : 'text-[#706f6c] dark:text-[#A1A09A]'
                    "
                >
                    {{ s.label }}
                </span>
            </div>
        </div>

        <p
            v-if="errorMessage"
            class="rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-600 dark:text-red-400"
        >
            {{ errorMessage }}
        </p>

        <div class="flex flex-col gap-2 text-xs">
            <div class="flex items-center gap-2">
                <span class="text-[#706f6c] dark:text-[#A1A09A]"
                    >Source tx:</span
                >
                <a
                    :href="sourceExplorer"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 font-mono text-[#1b1b18] underline dark:text-[#EDEDEC]"
                >
                    {{ sourceTxHash.slice(0, 10) }}…
                    <ExternalLink class="h-3 w-3" />
                </a>
            </div>
            <div v-if="destExplorer" class="flex items-center gap-2">
                <span class="text-[#706f6c] dark:text-[#A1A09A]">
                    Destination tx:
                </span>
                <a
                    :href="destExplorer"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 font-mono text-[#1b1b18] underline dark:text-[#EDEDEC]"
                >
                    {{ destinationTxHash!.slice(0, 10) }}…
                    <ExternalLink class="h-3 w-3" />
                </a>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-[#706f6c] dark:text-[#A1A09A]">Sent to:</span>
                <AddressDisplay :address="destinationAddress" size="sm" />
            </div>
        </div>

        <button
            v-if="status === 'completed' || status === 'failed'"
            type="button"
            class="w-full rounded-lg border border-[#19140035] py-3 text-sm font-medium text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
            @click="$emit('reset')"
        >
            Start another bridge
        </button>
    </div>
</template>
