<script setup lang="ts">
import { Copy, Check } from 'lucide-vue-next';
import { ref } from 'vue';

const props = withDefaults(
    defineProps<{
        address: string;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { size: 'md' },
);

const copied = ref(false);
const expanded = ref(false);

const truncated = (addr: string): string => {
    if (addr.length <= 16) {
        return addr;
    }

    return `${addr.slice(0, 8)}…${addr.slice(-8)}`;
};

const handleCopy = async () => {
    try {
        await navigator.clipboard.writeText(props.address);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // ignore — clipboard might be unavailable
    }
};

const sizeClass = {
    sm: 'text-xs',
    md: 'text-sm',
    lg: 'text-lg',
}[props.size];
</script>

<template>
    <div class="flex items-center gap-2">
        <button
            type="button"
            class="rounded bg-[#19140010] px-2 py-1 font-mono break-all transition-colors hover:bg-[#19140020] dark:bg-[#3E3E3A] dark:hover:bg-[#4a4a46]"
            :class="sizeClass"
            :title="address"
            @click="expanded = !expanded"
        >
            {{ expanded ? address : truncated(address) }}
        </button>
        <button
            type="button"
            class="rounded p-1 text-[#706f6c] hover:text-[#1b1b18] dark:text-[#A1A09A] dark:hover:text-[#EDEDEC]"
            :title="copied ? 'Copied' : 'Copy'"
            @click="handleCopy"
        >
            <Check v-if="copied" class="h-4 w-4 text-green-500" />
            <Copy v-else class="h-4 w-4" />
        </button>
    </div>
</template>
