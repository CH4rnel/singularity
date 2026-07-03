<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Clock, Loader2, XCircle } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import BridgeWizard from '@/components/bridge/BridgeWizard.vue';
import { useBridgeAnalytics } from '@/composables/useBridgeAnalytics';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { bridgeRoute } from '@/lib/addressValidation';
import { initBridgeConfig } from '@/lib/bridgeConfig';
import type {
    PublicChain,
    PublicRouteData,
    PublicToken,
} from '@/lib/bridgeConfig';

type BridgeHistoryItem = {
    id: number;
    direction: string;
    source_chain: string;
    source_tx_hash: string;
    sender_address: string;
    recipient_address: string;
    amount: string;
    status: string;
    destination_tx_hash: string | null;
    created_at: string;
    completed_at: string | null;
};

const props = withDefaults(
    defineProps<{
        price?: {
            priceSol: number;
            priceUsd: number;
        } | null;
        bridgeHistory?: BridgeHistoryItem[];
        bridgeRelayerEvm?: string | null;
        bridgeChains?: PublicChain[];
        bridgeRoutes?: PublicRouteData[];
        bridgeTokens?: PublicToken[];
        bridgeDirections?: string[];
        bridgeYentenDepositAddress?: string | null;
        bridgeFeeConfig?: { flatUsd: number; rateBps: number };
        bridgeGasDrop?: { enabled: boolean; amount: string };
        bridgeConvert?: { enabled: boolean; rate: number };
    }>(),
    {
        price: null,
        bridgeHistory: () => [],
        bridgeRelayerEvm: null,
        bridgeChains: () => [],
        bridgeRoutes: () => [],
        bridgeTokens: () => [],
        bridgeDirections: () => [],
        bridgeYentenDepositAddress: null,
        bridgeFeeConfig: () => ({ flatUsd: 0.1, rateBps: 0 }),
        bridgeGasDrop: () => ({ enabled: true, amount: '0.01' }),
        bridgeConvert: () => ({ enabled: true, rate: 1000 }),
    },
);

// Server config becomes the authoritative chain/route/token source before the
// wizard renders — adding an EVM chain in config/bridge.php needs no JS edits.
initBridgeConfig(props.bridgeChains, props.bridgeRoutes, props.bridgeTokens);

const page = usePage();
const evmWallet = useWallet();
const solanaWallet = useSolanaWallet();
const analytics = useBridgeAnalytics();

const user = computed(
    () =>
        page.props.auth?.user as
            | {
                  wallet_address?: string | null;
                  solana_wallet_address?: string | null;
              }
            | undefined,
);

onMounted(() => {
    evmWallet.restore(user.value?.wallet_address);
    solanaWallet.restore(user.value?.solana_wallet_address);
    analytics.track('page_view');
});

const directionLabel = (direction: string): string => {
    const route = bridgeRoute(direction);

    if (route.direction !== direction) {
        return direction;
    }

    return `${route.sourceLabel} -> ${route.destinationLabel}`;
};

const formatAddr = (addr: string) =>
    addr.length > 12 ? `${addr.slice(0, 6)}...${addr.slice(-4)}` : addr;

const statusIcon = (status: string) => {
    switch (status) {
        case 'completed':
            return CheckCircle2;
        case 'failed':
            return XCircle;
        case 'processing':
            return Loader2;
        default:
            return Clock;
    }
};

const statusColor = (status: string) => {
    switch (status) {
        case 'completed':
            return 'text-green-500';
        case 'failed':
            return 'text-red-500';
        case 'processing':
            return 'text-yellow-500 animate-spin';
        default:
            return 'text-muted-foreground';
    }
};
</script>

<template>
    <Head title="Cyberia Bridge" />
    <div class="flex flex-col items-center">
        <div
            class="flex w-full flex-1 flex-col items-center p-6 lg:justify-center lg:p-8"
        >
            <div
                class="flex w-full max-w-lg flex-col items-center gap-6 opacity-100 transition-opacity duration-750 lg:grow starting:opacity-0"
            >
                <!-- Token price -->
                <div
                    v-if="price"
                    class="flex w-full items-center justify-between rounded-lg border border-border px-5 py-3"
                >
                    <div>
                        <p class="text-sm font-semibold text-foreground">
                            CYBER.sol price
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-mono text-sm text-foreground">
                            1 CYBER.sol = {{ price.priceSol }} SOL
                        </p>
                        <p class="text-xs text-muted-foreground">
                            ${{ price.priceUsd }}
                        </p>
                    </div>
                </div>

                <BridgeWizard
                    :relayer-evm-address="props.bridgeRelayerEvm"
                    :available-directions="props.bridgeDirections"
                    :yenten-deposit-address="props.bridgeYentenDepositAddress"
                    :cyber-sol-usd="
                        props.price ? Number(props.price.priceUsd) : null
                    "
                    :fee-config="props.bridgeFeeConfig"
                    :gas-drop-config="props.bridgeGasDrop"
                    :convert-config="props.bridgeConvert"
                />

                <!-- Bridge History -->
                <div
                    v-if="bridgeHistory.length > 0"
                    class="w-full rounded-xl border border-border p-5"
                >
                    <h3 class="mb-3 text-sm font-semibold text-foreground">
                        History
                    </h3>
                    <div class="flex flex-col gap-2">
                        <div
                            v-for="item in bridgeHistory"
                            :key="item.id"
                            class="flex items-center justify-between rounded-lg bg-muted/50 px-4 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-xs font-medium text-foreground"
                                    >
                                        {{ directionLabel(item.direction) }}
                                    </span>
                                    <span
                                        class="font-mono text-xs text-muted-foreground"
                                    >
                                        {{ item.amount }}
                                    </span>
                                </div>
                                <p
                                    class="mt-0.5 truncate font-mono text-[10px] text-muted-foreground"
                                >
                                    {{ formatAddr(item.sender_address) }}
                                    ->
                                    {{ formatAddr(item.recipient_address) }}
                                </p>
                            </div>
                            <component
                                :is="statusIcon(item.status)"
                                class="h-4 w-4 shrink-0"
                                :class="statusColor(item.status)"
                            />
                        </div>
                    </div>
                </div>
            </div>
            <div class="hidden h-14.5 lg:block"></div>
        </div>
    </div>
</template>
