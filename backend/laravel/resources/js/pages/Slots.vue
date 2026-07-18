<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PoolSidebar from '@/components/slots/PoolSidebar.vue';
import SlotReels from '@/components/slots/SlotReels.vue';
import SpinResultModal from '@/components/slots/SpinResultModal.vue';
import { useSlotMachine } from '@/composables/useSlotMachine';
import { useSolanaWallet } from '@/composables/useSolanaWallet';

const wallet = useSolanaWallet();
// RPC comes from /api/slots/pool (driven by SLOT_CLUSTER on the server).
const machine = useSlotMachine();

const selectedMint = ref<string | null>(null);
const betAmount = ref<string>('1');
const clientSeed = ref<string>(Math.random().toString(36).slice(2));

const selectedToken = computed(
    () =>
        machine.pool.value?.tokens.find((t) => t.mint === selectedMint.value) ??
        null,
);

const betRaw = computed<string>(() => {
    if (!selectedToken.value) {
        return '0';
    }

    const ui = parseFloat(betAmount.value || '0');

    if (!isFinite(ui) || ui <= 0) {
        return '0';
    }

    return BigInt(
        Math.round(ui * 10 ** selectedToken.value.decimals),
    ).toString();
});

const canSpin = computed(
    () =>
        wallet.isConnected.value &&
        selectedMint.value !== null &&
        BigInt(betRaw.value) > 0n &&
        !machine.isSpinning.value,
);

onMounted(async () => {
    await machine.loadPool();

    if (!selectedMint.value && machine.pool.value?.tokens.length) {
        selectedMint.value = machine.pool.value.tokens[0].mint;
    }
});

const handleSpin = async () => {
    if (!canSpin.value || !selectedMint.value) {
        return;
    }

    const solana = wallet.getTransactionProvider(
        machine.pool.value?.cluster ?? 'mainnet',
    );

    if (!solana) {
        return;
    }

    clientSeed.value = Math.random().toString(36).slice(2);

    await machine
        .spin(solana, selectedMint.value, betRaw.value, clientSeed.value)
        .catch(() => {
            // Error surfaced via machine.error.
        });
};
</script>

<template>
    <div class="slots-page">
        <header>
            <h1>pump.fun roulette</h1>
            <p>
                Любой токен на ставку, любой токен в приз. Часть ставки
                сжигается.
            </p>
        </header>

        <div class="layout">
            <PoolSidebar :tokens="machine.pool.value?.tokens ?? []" />

            <main>
                <SlotReels
                    :tokens="machine.pool.value?.tokens ?? []"
                    :target="machine.lastResult.value?.reels ?? null"
                    :spinning="machine.isSpinning.value"
                />

                <div v-if="!wallet.isConnected.value" class="connect">
                    <button @click="wallet.connect()">
                        Подключить Solana wallet
                    </button>
                </div>

                <div v-else class="controls">
                    <label>
                        Токен ставки:
                        <select v-model="selectedMint">
                            <option
                                v-for="t in machine.pool.value?.tokens ?? []"
                                :key="t.mint"
                                :value="t.mint"
                            >
                                {{ t.symbol ?? t.mint.slice(0, 8) }} ({{
                                    (t.weight * 100).toFixed(1)
                                }}%)
                            </option>
                        </select>
                    </label>
                    <label>
                        Размер:
                        <input
                            v-model="betAmount"
                            type="number"
                            min="0"
                            step="0.000001"
                        />
                    </label>
                    <button :disabled="!canSpin" @click="handleSpin">
                        {{ machine.isSpinning.value ? 'Крутится...' : 'SPIN' }}
                    </button>
                </div>

                <p v-if="machine.error.value" class="error">
                    {{ machine.error.value }}
                </p>
            </main>
        </div>

        <SpinResultModal
            :result="machine.lastResult.value"
            :tokens="machine.pool.value?.tokens ?? []"
            :cluster="machine.pool.value?.cluster"
            @close="machine.lastResult.value = null"
        />
    </div>
</template>

<style scoped>
.slots-page {
    padding: 32px;
    color: var(--foreground);
    background: var(--background);
    min-height: 100vh;
}
header h1 {
    font-size: 2em;
    margin: 0 0 8px 0;
}
.layout {
    display: flex;
    gap: 32px;
    margin-top: 24px;
}
.controls {
    margin-top: 16px;
    display: flex;
    gap: 12px;
    align-items: center;
}
.controls label {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 0.9em;
}
.controls select,
.controls input {
    background: var(--card);
    color: var(--foreground);
    border: 1px solid var(--border);
    padding: 6px 8px;
    border-radius: 4px;
}
.controls button,
.connect button {
    background: #4ade80;
    color: #000;
    border: none;
    border-radius: 6px;
    padding: 10px 24px;
    font-weight: bold;
    cursor: pointer;
}
.controls button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.error {
    color: #f87171;
    margin-top: 12px;
}
</style>
