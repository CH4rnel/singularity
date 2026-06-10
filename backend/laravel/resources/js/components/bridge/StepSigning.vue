<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

import type { BridgeDirection } from '@/lib/addressValidation';

const props = defineProps<{
    direction: BridgeDirection;
    phase: 'signing' | 'submitting';
}>();

const chainName = computed(() =>
    props.direction === 'sol_to_evm' ? 'your Solana wallet' : 'your EVM wallet',
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
                        ? 'Check your wallet popup. Do not close this page.'
                        : 'This usually takes a few seconds.'
                }}
            </p>
        </div>
    </div>
</template>
