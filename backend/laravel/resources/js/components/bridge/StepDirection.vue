<script setup lang="ts">
import { ArrowRight } from 'lucide-vue-next';

import type { BridgeDirection } from '@/lib/addressValidation';

defineEmits<{
    (e: 'select', direction: BridgeDirection): void;
}>();
</script>

<template>
    <div class="flex w-full flex-col gap-3">
        <h2 class="text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">
            Where do you want to bridge?
        </h2>
        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">
            Connect any wallet that supports the source chain — MetaMask,
            Phantom, or anything else. The destination is just an address.
        </p>
        <button
            type="button"
            class="group flex items-center justify-between rounded-xl border border-[#19140035] p-5 text-left transition-colors hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
            @click="$emit('select', 'sol_to_evm')"
        >
            <div>
                <p class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                    Solana → Cyberia EVM
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Sign with any Solana wallet, receive on any EVM address.
                </p>
            </div>
            <ArrowRight
                class="h-5 w-5 text-[#706f6c] transition-transform group-hover:translate-x-1 dark:text-[#A1A09A]"
            />
        </button>
        <button
            type="button"
            class="group flex items-center justify-between rounded-xl border border-[#19140035] p-5 text-left transition-colors hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
            @click="$emit('select', 'evm_to_sol')"
        >
            <div>
                <p class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">
                    Cyberia EVM → Solana
                </p>
                <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Sign with any EVM wallet, receive on any Solana address.
                </p>
            </div>
            <ArrowRight
                class="h-5 w-5 text-[#706f6c] transition-transform group-hover:translate-x-1 dark:text-[#A1A09A]"
            />
        </button>
    </div>
</template>
