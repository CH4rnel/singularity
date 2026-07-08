<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

import { bridgeRoute } from '@/lib/addressValidation';
import type { BridgeDirection } from '@/lib/addressValidation';

const props = defineProps<{
    direction: BridgeDirection;
    phase: 'signing' | 'submitting';
}>();

const chainName = computed(() => {
    switch (bridgeRoute(props.direction).sourceWallet) {
        case 'solana':
            return 'your Solana wallet';
        case 'ton':
            return 'your TON wallet (Tonkeeper)';
        default:
            return 'your EVM wallet';
    }
});

// TON deposits keep the signing phase alive while the transaction finalizes
// and the indexer picks it up (~15–60 s) — say so instead of implying a stuck
// wallet popup.
const tonSource = computed(
    () => bridgeRoute(props.direction).sourceWallet === 'ton',
);
</script>

<template>
    <div class="flex w-full flex-col items-center gap-4 py-12 text-center">
        <Loader2
            class="h-12 w-12 animate-spin text-[#1b1b18] dark:text-[#EDEDEC]"
        />
        <div>
            <p class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                {{
                    phase === 'signing'
                        ? `Confirm the transaction in ${chainName}`
                        : 'Sending to relayer…'
                }}
            </p>
            <p class="mt-1 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                {{
                    phase === 'signing'
                        ? tonSource
                            ? 'Approve in the wallet, then we wait for the TON network to confirm (~30 s). Do not close this page.'
                            : 'Check your wallet popup. Do not close this page.'
                        : 'This usually takes a few seconds.'
                }}
            </p>
        </div>
    </div>
</template>
