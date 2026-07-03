<script setup lang="ts">
import { computed, ref } from 'vue';
import type { SpinResult, SlotToken } from '@/composables/useSlotMachine';

const props = defineProps<{
    result: SpinResult | null;
    tokens: SlotToken[];
    cluster?: 'devnet' | 'mainnet';
}>();

const explorerUrl = (sig: string): string => {
    const suffix = props.cluster === 'devnet' ? '?cluster=devnet' : '';

    return `https://solscan.io/tx/${sig}${suffix}`;
};

const emit = defineEmits<{ close: [] }>();

const auditOpen = ref(false);

const headline = computed(() => {
    if (!props.result) {
        return '';
    }

    switch (props.result.outcome_type) {
        case 'jackpot':
            return '🎰 ДЖЕКПОТ';
        case 'win':
            return '✨ Победа';
        case 'loss':
            return 'Мимо';
        default:
            return '...';
    }
});

const formatAmount = (raw: string, decimals: number): string => {
    const big = BigInt(raw);
    const denom = BigInt(10) ** BigInt(decimals);
    const whole = big / denom;
    const frac = big % denom;

    if (frac === BigInt(0)) {
        return whole.toString();
    }

    const fracStr = frac.toString().padStart(decimals, '0').replace(/0+$/, '');

    return `${whole}.${fracStr}`;
};

const symbolFor = (mint: string): string =>
    props.tokens.find((t) => t.mint === mint)?.symbol ?? mint.slice(0, 4);

const auditJsSnippet = computed(() => {
    if (!props.result) {
        return '';
    }

    return `// Verify outcome locally
const seed = ${JSON.stringify(props.result.server_seed)};
const client = ${JSON.stringify(props.result.client_seed)};
const nonce = ${props.result.nonce};
crypto.subtle.digest('SHA-256', new TextEncoder().encode(seed))
  .then(h => console.log('match:', [...new Uint8Array(h)].map(b => b.toString(16).padStart(2,'0')).join('') === ${JSON.stringify(props.result.server_seed_hash)}));`;
});
</script>

<template>
    <div v-if="result" class="modal-backdrop" @click.self="emit('close')">
        <div class="modal">
            <h2>{{ headline }}</h2>
            <div v-if="result.prize_payload.length > 0" class="prize">
                <div
                    v-for="line in result.prize_payload"
                    :key="line.mint"
                    class="prize-line"
                >
                    <span class="amount"
                        >+{{ formatAmount(line.amount, line.decimals) }}</span
                    >
                    <span class="symbol">{{
                        line.symbol ?? symbolFor(line.mint)
                    }}</span>
                </div>
            </div>
            <p
                v-if="result.burn_amount && BigInt(result.burn_amount) > 0n"
                class="burn"
            >
                🔥 Сожжено: {{ formatAmount(result.burn_amount, 0) }} raw units
            </p>
            <p v-if="result.payout_tx_hash" class="tx">
                TX:
                <a :href="explorerUrl(result.payout_tx_hash)" target="_blank"
                    >{{ result.payout_tx_hash.slice(0, 12) }}…</a
                >
            </p>

            <button class="audit-toggle" @click="auditOpen = !auditOpen">
                {{ auditOpen ? '▼' : '▶' }} Аудит честности
            </button>
            <div v-if="auditOpen" class="audit">
                <div>
                    <b>server_seed:</b> <code>{{ result.server_seed }}</code>
                </div>
                <div>
                    <b>sha256:</b> <code>{{ result.server_seed_hash }}</code>
                </div>
                <div>
                    <b>client_seed:</b> <code>{{ result.client_seed }}</code>
                </div>
                <div>
                    <b>nonce:</b> <code>{{ result.nonce }}</code>
                </div>
                <pre>{{ auditJsSnippet }}</pre>
            </div>

            <button class="close" @click="emit('close')">Ещё раз</button>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100;
}
.modal {
    background: #181818;
    color: #eee;
    padding: 24px;
    border-radius: 12px;
    max-width: 480px;
    width: 90%;
}
h2 {
    margin: 0 0 16px 0;
}
.prize-line {
    display: flex;
    gap: 8px;
    font-size: 1.4em;
}
.amount {
    color: #4ade80;
    font-weight: bold;
}
.burn {
    color: #f97316;
}
.audit-toggle {
    background: none;
    border: 1px solid #444;
    color: #aaa;
    padding: 4px 12px;
    cursor: pointer;
    border-radius: 4px;
    margin-top: 16px;
}
.audit {
    font-size: 0.8em;
    margin-top: 8px;
    word-break: break-all;
}
.audit code {
    color: #93c5fd;
}
.audit pre {
    background: #000;
    padding: 8px;
    border-radius: 4px;
    white-space: pre-wrap;
}
.close {
    margin-top: 16px;
    padding: 8px 24px;
    background: #4ade80;
    color: #000;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}
.tx a {
    color: #93c5fd;
}
</style>
