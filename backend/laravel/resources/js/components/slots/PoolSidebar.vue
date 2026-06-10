<script setup lang="ts">
import type { SlotToken } from '@/composables/useSlotMachine';

defineProps<{ tokens: SlotToken[] }>();
</script>

<template>
    <aside class="pool-sidebar">
        <h3>В машине</h3>
        <ul>
            <li v-for="t in tokens" :key="t.mint">
                <img
                    v-if="t.logo_url"
                    :src="t.logo_url"
                    :alt="t.symbol ?? ''"
                />
                <span class="symbol">{{ t.symbol ?? t.mint.slice(0, 4) }}</span>
                <span class="weight">{{ (t.weight * 100).toFixed(1) }}%</span>
            </li>
        </ul>
    </aside>
</template>

<style scoped>
.pool-sidebar {
    background: #1a1a1a;
    border-radius: 8px;
    padding: 16px;
    color: #ddd;
    width: 240px;
}

ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

li {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    border-bottom: 1px solid #2a2a2a;
}

li img {
    width: 24px;
    height: 24px;
    object-fit: contain;
}

.symbol {
    flex: 1;
}

.weight {
    font-variant-numeric: tabular-nums;
    color: #888;
}
</style>
