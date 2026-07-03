<script setup lang="ts">
import { ArrowRight, ArrowUpDown, PenLine, Wallet } from 'lucide-vue-next';

import { computed, ref, watch } from 'vue';

import { bridgeRoutesList } from '@/lib/addressValidation';
import type { BridgeDirection, BridgeRoute } from '@/lib/addressValidation';

const emit = defineEmits<{
    (e: 'select', direction: BridgeDirection): void;
}>();

const props = withDefaults(
    defineProps<{
        availableDirections?: string[];
    }>(),
    {
        availableDirections: () => [],
    },
);

// Server config (initBridgeConfig) is authoritative; the static table is the
// pre-init fallback. availableDirections filters to operational routes.
const routes = computed<BridgeRoute[]>(() => {
    const configured = bridgeRoutesList();

    return props.availableDirections.length === 0
        ? configured
        : configured.filter((route) =>
              props.availableDirections.includes(route.direction),
          );
});

const chainLabel = computed<Record<string, string>>(() => {
    const labels: Record<string, string> = {};

    for (const route of routes.value) {
        labels[route.source] = route.sourceLabel;
        labels[route.destination] = route.destinationLabel;
    }

    return labels;
});

const uniq = (values: string[]): string[] => [...new Set(values)];

const sourceChains = computed(() =>
    uniq(routes.value.map((route) => route.source)),
);

const from = ref<string>('');
const to = ref<string>('');

const destinationChains = computed(() =>
    uniq(
        routes.value
            .filter((route) => route.source === from.value)
            .map((route) => route.destination),
    ),
);

watch(
    routes,
    (list) => {
        if (list.length === 0) {
            return;
        }

        if (!list.some((route) => route.source === from.value)) {
            from.value = list[0].source;
        }
    },
    { immediate: true },
);

watch(
    [from, destinationChains],
    () => {
        if (!destinationChains.value.includes(to.value)) {
            to.value = destinationChains.value[0] ?? '';
        }
    },
    { immediate: true },
);

const selectedRoute = computed(
    () =>
        routes.value.find(
            (route) =>
                route.source === from.value && route.destination === to.value,
        ) ?? null,
);

const canFlip = computed(() =>
    routes.value.some(
        (route) =>
            route.source === to.value && route.destination === from.value,
    ),
);

const flip = () => {
    if (!canFlip.value) {
        return;
    }

    [from.value, to.value] = [to.value, from.value];
};

const proceed = () => {
    if (selectedRoute.value) {
        emit('select', selectedRoute.value.direction);
    }
};
</script>

<template>
    <div class="flex w-full flex-col gap-4">
        <div>
            <h2 class="text-lg font-semibold text-foreground">
                Where do you want to bridge?
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Pick the source and destination chains — tokens come next.
            </p>
        </div>

        <!-- From -->
        <div class="rounded-xl border border-border bg-card p-4">
            <p
                class="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                From
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="chain in sourceChains"
                    :key="chain"
                    type="button"
                    class="rounded-full border px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        from === chain
                            ? 'border-brand-cyan bg-accent text-accent-foreground'
                            : 'border-border text-muted-foreground hover:border-brand-cyan/40 hover:text-foreground'
                    "
                    @click="from = chain"
                >
                    {{ chainLabel[chain] ?? chain }}
                </button>
            </div>
        </div>

        <!-- Swap -->
        <div class="-my-6 flex justify-center">
            <button
                type="button"
                class="z-10 rounded-full border border-border bg-background p-2 transition-transform hover:rotate-180 disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!canFlip"
                title="Swap direction"
                @click="flip"
            >
                <ArrowUpDown class="h-4 w-4 text-muted-foreground" />
            </button>
        </div>

        <!-- To -->
        <div class="rounded-xl border border-border bg-card p-4">
            <p
                class="mb-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase"
            >
                To
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="chain in destinationChains"
                    :key="chain"
                    type="button"
                    class="rounded-full border px-4 py-2 text-sm font-medium transition-colors"
                    :class="
                        to === chain
                            ? 'border-brand-cyan bg-accent text-accent-foreground'
                            : 'border-border text-muted-foreground hover:border-brand-cyan/40 hover:text-foreground'
                    "
                    @click="to = chain"
                >
                    {{ chainLabel[chain] ?? chain }}
                </button>
            </div>
        </div>

        <!-- Route hint -->
        <p
            v-if="selectedRoute"
            class="flex items-start gap-2 text-xs text-muted-foreground"
        >
            <PenLine
                v-if="selectedRoute.sourceWallet === 'manual'"
                class="mt-0.5 h-3.5 w-3.5 shrink-0"
            />
            <Wallet v-else class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
                {{
                    selectedRoute.sourceWallet === 'manual'
                        ? `Send from any ${selectedRoute.sourceLabel} wallet to the bridge address, then paste the transaction hash — verification and delivery are automatic.`
                        : `Sign with your ${selectedRoute.sourceLabel} wallet, receive on ${selectedRoute.destinationLabel}.`
                }}
            </span>
        </p>

        <button
            type="button"
            class="group flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 font-medium text-primary-foreground transition-opacity disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="!selectedRoute"
            @click="proceed"
        >
            Continue
            <ArrowRight
                class="h-4 w-4 transition-transform group-hover:translate-x-1"
            />
        </button>
    </div>
</template>
