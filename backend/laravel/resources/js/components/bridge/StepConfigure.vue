<script setup lang="ts">
import { Wallet, Clock } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

import type { RecentDestination } from '@/composables/useBridgeFlow';
import { validateDestination } from '@/lib/addressValidation';
import type { BridgeDirection } from '@/lib/addressValidation';
import { SUPPORTED_TOKEN_SYMBOLS } from '@/lib/bridgeTokens';
import type { BridgeTokenSymbol } from '@/lib/bridgeTokens';

const props = defineProps<{
    direction: BridgeDirection;
    token: BridgeTokenSymbol;
    amount: string;
    destinationAddress: string;
    sourceWalletConnected: boolean;
    sourceWalletAddress: string | null;
    sourceWalletConnecting: boolean;
    sourceBalance: string | null;
    recent: RecentDestination[];
}>();

const emit = defineEmits<{
    (e: 'update:amount', v: string): void;
    (e: 'update:destinationAddress', v: string): void;
    (e: 'update:token', v: BridgeTokenSymbol): void;
    (e: 'connect-source'): void;
    (e: 'next'): void;
    (e: 'back'): void;
}>();

const tokenSymbols = SUPPORTED_TOKEN_SYMBOLS;

const localToken = computed({
    get: () => props.token,
    set: (v) => emit('update:token', v),
});

const localAmount = computed({
    get: () => props.amount,
    set: (v) => emit('update:amount', v),
});

const localDestination = computed({
    get: () => props.destinationAddress,
    set: (v) => emit('update:destinationAddress', v),
});

const validation = ref(validateDestination(props.direction, ''));

watch(
    () => [props.direction, props.destinationAddress] as const,
    ([dir, addr]) => {
        validation.value = validateDestination(dir, addr);
    },
    { immediate: true },
);

const sourceLabel = computed(() =>
    props.direction === 'sol_to_evm' ? 'Solana' : 'Cyberia EVM',
);

const destChainLabel = computed(() =>
    props.direction === 'sol_to_evm' ? 'Cyberia EVM' : 'Solana',
);

const destPlaceholder = computed(() =>
    props.direction === 'sol_to_evm'
        ? '0x… EVM address that will receive the funds'
        : 'Solana address that will receive the funds',
);

const amountNum = computed(() => parseFloat(localAmount.value));

const amountValid = computed(() => amountNum.value > 0);

const amountExceedsBalance = computed(() => {
    const bal = parseFloat(props.sourceBalance ?? '0');

    return amountNum.value > 0 && bal > 0 && amountNum.value > bal;
});

const setMax = () => {
    if (props.sourceBalance) {
        localAmount.value = props.sourceBalance;
    }
};

const canProceed = computed(
    () =>
        props.sourceWalletConnected &&
        amountValid.value &&
        !amountExceedsBalance.value &&
        validation.value.valid,
);

const useRecent = (entry: RecentDestination) => {
    localDestination.value = entry.address;
};

const formatRelative = (ts: number): string => {
    const diff = Date.now() - ts;
    const day = 86_400_000;

    if (diff < day) {
        return 'today';
    }

    if (diff < 2 * day) {
        return 'yesterday';
    }

    return `${Math.floor(diff / day)}d ago`;
};
</script>

<template>
    <div class="flex w-full flex-col gap-4">
        <header class="flex items-center justify-between">
            <h2
                class="text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
            >
                Configure transfer
            </h2>
            <button
                type="button"
                class="text-xs text-[#706f6c] underline hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
                @click="$emit('back')"
            >
                Back
            </button>
        </header>

        <!-- Source wallet -->
        <div
            class="flex items-center justify-between rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]"
        >
            <div class="flex items-center gap-2">
                <Wallet class="h-4 w-4 text-[#1b1b18] dark:text-[#EDEDEC]" />
                <div>
                    <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        From {{ sourceLabel }}
                    </p>
                    <p
                        v-if="sourceWalletConnected && sourceWalletAddress"
                        class="font-mono text-xs text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ sourceWalletAddress.slice(0, 6) }}…{{
                            sourceWalletAddress.slice(-4)
                        }}
                    </p>
                </div>
            </div>
            <button
                v-if="!sourceWalletConnected"
                type="button"
                class="rounded border border-[#19140035] px-3 py-1.5 text-xs text-[#1b1b18] hover:border-[#1915014a] disabled:opacity-50 dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                :disabled="sourceWalletConnecting"
                @click="$emit('connect-source')"
            >
                {{ sourceWalletConnecting ? 'Connecting…' : 'Connect' }}
            </button>
            <span v-else class="text-xs text-green-600 dark:text-green-400">
                Connected
            </span>
        </div>

        <!-- Token + Amount -->
        <div
            class="rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]"
        >
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                    Token:
                </span>
                <button
                    v-for="symbol in tokenSymbols"
                    :key="symbol"
                    type="button"
                    class="rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                    :class="
                        localToken === symbol
                            ? 'border-[#1b1b18] bg-[#1b1b18] text-white dark:border-[#EDEDEC] dark:bg-[#EDEDEC] dark:text-[#0a0a0a]'
                            : 'border-[#19140035] text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]'
                    "
                    @click="localToken = symbol"
                >
                    {{ symbol }}
                </button>
            </div>

            <div class="mb-2 flex items-center justify-between">
                <label
                    for="bridge-amount"
                    class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Amount
                </label>
                <span
                    v-if="sourceBalance !== null"
                    class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                >
                    Balance:
                    <span class="font-mono">{{
                        parseFloat(sourceBalance).toFixed(4)
                    }}</span>
                    <button
                        type="button"
                        class="ml-2 rounded bg-[#19140010] px-1.5 py-0.5 text-[10px] font-medium text-[#1b1b18] hover:bg-[#19140020] dark:bg-[#3E3E3A] dark:text-[#EDEDEC]"
                        @click="setMax"
                    >
                        MAX
                    </button>
                </span>
            </div>
            <input
                id="bridge-amount"
                v-model="localAmount"
                type="text"
                inputmode="decimal"
                placeholder="0.0"
                class="w-full bg-transparent text-2xl font-light text-[#1b1b18] outline-none placeholder:text-[#c4c4c0] dark:text-[#EDEDEC] dark:placeholder:text-[#555]"
                :class="{
                    'text-red-500 dark:text-red-400': amountExceedsBalance,
                }"
            />
            <p
                v-if="amountExceedsBalance"
                class="mt-1 text-xs text-red-500 dark:text-red-400"
            >
                Amount exceeds your balance
            </p>
        </div>

        <!-- Destination address -->
        <div
            class="rounded-lg border border-[#19140035] p-4 dark:border-[#3E3E3A]"
        >
            <label
                for="bridge-dest"
                class="mb-1 block text-xs text-[#706f6c] dark:text-[#A1A09A]"
            >
                Send to {{ destChainLabel }} address
            </label>
            <input
                id="bridge-dest"
                v-model="localDestination"
                type="text"
                autocomplete="off"
                spellcheck="false"
                :placeholder="destPlaceholder"
                class="w-full rounded border border-[#19140020] bg-[#FDFDFC] px-3 py-2 font-mono text-xs text-[#1b1b18] outline-none placeholder:text-[#c4c4c0] focus:border-[#1915014a] dark:border-[#3E3E3A] dark:bg-[#0a0a0a] dark:text-[#EDEDEC] dark:placeholder:text-[#555]"
                :class="{
                    'border-red-500 dark:border-red-500':
                        !validation.valid && localDestination.length > 0,
                }"
            />
            <p
                v-if="!validation.valid && localDestination.length > 0"
                class="mt-1 text-xs text-red-500 dark:text-red-400"
            >
                {{ validation.error }}
            </p>
            <p
                v-else-if="validation.warning"
                class="mt-1 text-xs text-yellow-600 dark:text-yellow-400"
            >
                {{ validation.warning }}
            </p>

            <!-- Recent addresses -->
            <div v-if="recent.length > 0" class="mt-3">
                <p
                    class="mb-1 text-[10px] tracking-wider text-[#706f6c] uppercase dark:text-[#A1A09A]"
                >
                    Recent destinations
                </p>
                <div class="flex flex-col gap-1">
                    <button
                        v-for="entry in recent"
                        :key="entry.address + entry.timestamp"
                        type="button"
                        class="flex items-center justify-between rounded border border-[#19140020] px-2 py-1.5 text-xs hover:border-[#1915014a] dark:border-[#3E3E3A] dark:hover:border-[#62605b]"
                        @click="useRecent(entry)"
                    >
                        <span
                            class="truncate font-mono text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ entry.address.slice(0, 8) }}…{{
                                entry.address.slice(-8)
                            }}
                        </span>
                        <span
                            class="flex shrink-0 items-center gap-1 text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            <Clock class="h-3 w-3" />
                            {{ entry.amount }} ·
                            {{ formatRelative(entry.timestamp) }}
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <button
            type="button"
            class="w-full rounded-lg bg-[#1b1b18] py-3 text-sm font-medium text-white transition-colors hover:bg-[#2d2d2a] disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#0a0a0a] dark:hover:bg-[#d4d4d0]"
            :disabled="!canProceed"
            @click="$emit('next')"
        >
            <span v-if="!sourceWalletConnected">Connect source wallet</span>
            <span v-else-if="!amountValid">Enter amount</span>
            <span v-else-if="amountExceedsBalance">Insufficient balance</span>
            <span v-else-if="!validation.valid">Enter destination address</span>
            <span v-else>Review</span>
        </button>
    </div>
</template>
