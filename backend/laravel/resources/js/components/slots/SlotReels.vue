<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { SlotToken } from '@/composables/useSlotMachine';

const props = defineProps<{
    tokens: SlotToken[];
    target: string[][] | null;
    spinning: boolean;
}>();

type Cell = { mint: string; logo: string | null; symbol: string | null };

const findToken = (mint: string): SlotToken | undefined =>
    props.tokens.find((t) => t.mint === mint);

const reelStrips = ref<Cell[][]>([[], [], []]);

const buildStrip = (): Cell[] => {
    const pool = props.tokens.length > 0 ? props.tokens : [];
    const strip: Cell[] = [];

    for (let i = 0; i < 24; i++) {
        const t = pool[Math.floor(Math.random() * Math.max(1, pool.length))];

        if (!t) {
            continue;
        }

        strip.push({ mint: t.mint, logo: t.logo_url, symbol: t.symbol });
    }

    return strip;
};

const rebuildStrips = () => {
    reelStrips.value = [buildStrip(), buildStrip(), buildStrip()];
};

watch(() => props.tokens, rebuildStrips, { immediate: true });

const settledMatrix = computed<Cell[][] | null>(() => {
    if (!props.target) {
        return null;
    }

    return props.target.map((row) =>
        row.map((mint) => {
            const t = findToken(mint);

            return {
                mint,
                logo: t?.logo_url ?? null,
                symbol: t?.symbol ?? mint.slice(0, 4),
            };
        }),
    );
});
</script>

<template>
    <div class="slot-reels">
        <div
            v-for="col in 3"
            :key="col"
            class="reel"
            :class="{ spinning }"
            :style="{ animationDelay: `${(col - 1) * 0.2}s` }"
        >
            <template v-if="settledMatrix && !spinning">
                <div v-for="row in 3" :key="row" class="cell settled">
                    <img
                        v-if="settledMatrix[row - 1][col - 1].logo"
                        :src="settledMatrix[row - 1][col - 1].logo!"
                        :alt="settledMatrix[row - 1][col - 1].symbol ?? ''"
                    />
                    <span v-else>{{
                        settledMatrix[row - 1][col - 1].symbol
                    }}</span>
                </div>
            </template>
            <template v-else>
                <div
                    v-for="(cell, idx) in reelStrips[col - 1]"
                    :key="idx"
                    class="cell"
                >
                    <img
                        v-if="cell.logo"
                        :src="cell.logo"
                        :alt="cell.symbol ?? ''"
                    />
                    <span v-else>{{ cell.symbol }}</span>
                </div>
            </template>
        </div>
    </div>
</template>

<style scoped>
.slot-reels {
    display: grid;
    grid-template-columns: repeat(3, 96px);
    gap: 8px;
    padding: 16px;
    border: 2px solid #444;
    border-radius: 12px;
    background: #111;
}

.reel {
    height: 288px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: #222;
    border-radius: 8px;
}

.cell {
    flex: 0 0 96px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ddd;
}

.cell img {
    width: 64px;
    height: 64px;
    object-fit: contain;
}

.reel.spinning {
    animation: spin 0.4s linear infinite;
}

@keyframes spin {
    from {
        transform: translateY(0);
    }
    to {
        transform: translateY(-96px);
    }
}
</style>
