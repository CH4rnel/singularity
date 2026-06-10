<script setup lang="ts">
import { AlertTriangle, Gift } from 'lucide-vue-next';
import { computed } from 'vue';

import type { BridgeDirection } from '@/lib/addressValidation';
import { DEFAULT_FEE, computeFee, isFeeBearing } from '@/lib/bridgeFee';
import type { BridgeFeeConfig } from '@/lib/bridgeFee';
import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';
import AddressDisplay from './AddressDisplay.vue';

const props = withDefaults(
    defineProps<{
        direction: BridgeDirection;
        token: BridgeTokenSymbol;
        amount: string;
        sourceAddress: string;
        destinationAddress: string;
        confirmed: boolean;
        cyberSolUsd?: number | null;
        feeConfig?: BridgeFeeConfig;
        gasDropPlanned?: boolean;
        gasDropAmount?: string;
    }>(),
    {
        cyberSolUsd: null,
        feeConfig: () => DEFAULT_FEE,
        gasDropPlanned: false,
        gasDropAmount: '0.01',
    },
);

const emit = defineEmits<{
    (e: 'update:confirmed', v: boolean): void;
    (e: 'confirm'): void;
    (e: 'back'): void;
}>();

const localConfirmed = computed({
    get: () => props.confirmed,
    set: (v) => emit('update:confirmed', v),
});

const sourceChain = computed(() =>
    props.direction === 'sol_to_evm' ? 'Solana' : 'Cyberia EVM',
);

const destChain = computed(() =>
    props.direction === 'sol_to_evm' ? 'Cyberia EVM' : 'Solana',
);

const feeResult = computed(() =>
    computeFee(
        props.token,
        props.amount,
        { cyberSolUsd: props.cyberSolUsd ?? null },
        props.feeConfig,
    ),
);

const hasFee = computed(() => isFeeBearing(props.token));

const fee = computed(() => feeResult.value.feeToken.toFixed(6));

const feeUsd = computed(() => feeResult.value.feeUsd.toFixed(2));

const youReceive = computed(() => {
    const amt = parseFloat(props.amount);
    const after = amt - feeResult.value.feeToken;

    return after > 0 ? after.toFixed(6) : '0';
});
</script>

<template>
    <div class="flex w-full flex-col gap-4">
        <header class="flex items-center justify-between">
            <h2
                class="text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Review and confirm
            </h2>
            <button
                type="button"
                class="text-xs text-[#706f6c] underline hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                @click="$emit('back')"
            >
                Back
            </button>
        </header>

        <div
            class="flex items-start gap-3 rounded-lg border border-yellow-500/40 bg-yellow-500/5 p-4 text-yellow-700 dark:text-yellow-300"
        >
            <AlertTriangle class="h-5 w-5 shrink-0" />
            <p class="text-sm">
                Funds sent to a wrong destination address are
                <strong>unrecoverable</strong>. Verify every character below
                before confirming.
            </p>
        </div>

        <div
            class="flex flex-col gap-3 rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]"
        >
            <div class="flex items-center justify-between">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Direction
                </span>
                <span
                    class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    {{ sourceChain }} → {{ destChain }}
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Sending
                </span>
                <span class="font-mono text-sm">{{ amount }} {{ token }}</span>
            </div>
            <div v-if="hasFee" class="flex items-center justify-between">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Bridge fee
                </span>
                <span class="font-mono text-sm">
                    ${{ feeUsd }}
                    <span class="text-[#706f6c] dark:text-[#A1A09A]">
                        (≈ {{ fee }} {{ token }})
                    </span>
                </span>
            </div>
            <div v-else class="flex items-center justify-between">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Bridge fee
                </span>
                <span
                    class="text-sm font-medium text-green-700 dark:text-green-400"
                >
                    Free
                </span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    You receive
                </span>
                <span
                    class="font-mono text-sm font-medium text-green-700 dark:text-green-400"
                >
                    {{ youReceive }} {{ token }}
                </span>
            </div>
            <div
                v-if="gasDropPlanned"
                class="flex items-start gap-2 rounded border border-green-500/30 bg-green-500/10 p-2 text-xs text-green-700 dark:text-green-300"
            >
                <Gift class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    First-time recipient bonus:
                    <strong>{{ gasDropAmount }} CYBER</strong>
                    (native gas) will be dropped so the destination can pay for
                    its first transaction.
                </span>
            </div>
            <div class="flex flex-col gap-1 pt-2">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    From {{ sourceChain }} address
                </span>
                <AddressDisplay :address="sourceAddress" size="sm" />
            </div>
            <div class="flex flex-col gap-1 pt-2">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    To {{ destChain }} address
                </span>
                <AddressDisplay :address="destinationAddress" size="lg" />
            </div>
        </div>

        <label
            class="flex cursor-pointer items-start gap-3 rounded-lg border border-[#19140035] p-3 dark:border-[#3E3E3A]"
        >
            <input
                v-model="localConfirmed"
                type="checkbox"
                class="mt-0.5 h-4 w-4 shrink-0 rounded border-[#19140035] dark:border-[#3E3E3A]"
            />
            <span class="text-sm text-[#1b1b18] dark:text-[#EDEDEC]">
                I have verified that
                <strong>{{ destinationAddress }}</strong> is the correct
                destination address on {{ destChain }}.
            </span>
        </label>

        <button
            type="button"
            class="w-full rounded-lg bg-[#1b1b18] py-3 text-sm font-medium text-white transition-colors hover:bg-[#2d2d2a] disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#0a0a0a] dark:hover:bg-[#d4d4d0]"
            :disabled="!localConfirmed"
            @click="$emit('confirm')"
        >
            Confirm and sign
        </button>
    </div>
</template>
