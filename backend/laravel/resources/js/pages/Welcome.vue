<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    ArrowDown,
    Wallet,
    Unplug,
    Loader2,
    CheckCircle2,
    XCircle,
    Clock,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useWallet } from '@/composables/useWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useBridge } from '@/composables/useBridge';
import { dashboard } from '@/routes';


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
        canRegister?: boolean;
        price?: {
            priceSol: number;
            priceUsd: number;
        } | null;
        bridgeHistory?: BridgeHistoryItem[];
    }>(),
    {
        canRegister: true,
        price: null,
        bridgeHistory: () => [],
    },
);

const page = usePage();
const evmWallet = useWallet();
const solanaWallet = useSolanaWallet();
const walletAuth = useWalletAuth();
const bridge = useBridge();

const feedbackError = ref<string | null>(null);
const feedbackSuccess = ref<string | null>(null);

// Bridge state
const bridgeDirection = ref<'sol_to_evm' | 'evm_to_sol'>('evm_to_sol');
const bridgeAmount = ref('');
const bridgeProcessing = ref(false);

const isAuthenticated = computed(() => !!page.props.auth?.user);

const user = computed(
    () =>
        page.props.auth?.user as
            | {
                  wallet_address?: string | null;
                  solana_wallet_address?: string | null;
              }
            | undefined,
);

const dashboardUrl = computed(() => {
    return page.props.currentTeam
        ? dashboard(page.props.currentTeam.slug).url
        : '/';
});

const bothWalletsConnected = computed(
    () => evmWallet.isConnected.value && solanaWallet.isConnected.value,
);

const sourceLabel = computed(() =>
    bridgeDirection.value === 'sol_to_evm' ? 'Solana' : 'Cyberia EVM',
);
const destLabel = computed(() =>
    bridgeDirection.value === 'sol_to_evm' ? 'Cyberia EVM' : 'Solana',
);
const sourceTokenLabel = computed(() =>
    bridgeDirection.value === 'sol_to_evm' ? 'CYBER.sol' : 'CYBER.sol',
);
const destTokenLabel = computed(() =>
    bridgeDirection.value === 'sol_to_evm' ? 'CYBER.sol' : 'CYBER.sol',
);

const sourceBalance = computed(() => {
    if (bridgeDirection.value === 'evm_to_sol') {
        // Sending CYBER.sol from EVM back to Solana
        return bridge.cyberSolBalance.value;
    }
    // Sending CYBER.sol from Solana to EVM
    return bridge.solanaCyberBalance.value;
});

// Show balances
const evmCyberSolDisplay = computed(() =>
    bridge.cyberSolBalance.value ? parseFloat(bridge.cyberSolBalance.value).toFixed(4) : null,
);
const solCyberSolDisplay = computed(() =>
    bridge.solanaCyberBalance.value ? parseFloat(bridge.solanaCyberBalance.value).toFixed(4) : null,
);

const BRIDGE_FEE_RATE = 0.01;

const bridgeFee = computed(() => {
    const amt = parseFloat(bridgeAmount.value);
    if (!amt || amt <= 0) return null;
    return (amt * BRIDGE_FEE_RATE).toFixed(6);
});

const amountAfterFee = computed(() => {
    const amt = parseFloat(bridgeAmount.value);
    if (!amt || amt <= 0) return null;
    return (amt * (1 - BRIDGE_FEE_RATE)).toFixed(6);
});

const amountExceedsBalance = computed(() => {
    const amt = parseFloat(bridgeAmount.value);
    const bal = parseFloat(sourceBalance.value ?? '0');
    return amt > 0 && bal > 0 && amt > bal;
});

const refreshBalances = async () => {
    if (evmWallet.isConnected.value && evmWallet.address.value) {
        bridge.fetchCyberSolBalance(evmWallet.address.value);
    }
    if (solanaWallet.isConnected.value && solanaWallet.address.value) {
        bridge.fetchSolanaCyberBalance(solanaWallet.address.value);
    }
};

onMounted(() => {
    evmWallet.restore(user.value?.wallet_address);
    solanaWallet.restore(user.value?.solana_wallet_address);
});

// Fetch balances when wallets connect
watch(() => evmWallet.isConnected.value, (connected) => {
    if (connected) refreshBalances();
}, { immediate: true });

watch(() => solanaWallet.isConnected.value, (connected) => {
    if (connected) refreshBalances();
}, { immediate: true });

const flipDirection = () => {
    bridgeDirection.value =
        bridgeDirection.value === 'sol_to_evm' ? 'evm_to_sol' : 'sol_to_evm';
};

const setMaxAmount = () => {
    if (sourceBalance.value) {
        bridgeAmount.value = sourceBalance.value;
    }
};

// --- Bridge submit ---
const handleBridgeSubmit = async () => {
    feedbackError.value = null;
    feedbackSuccess.value = null;

    const amount = parseFloat(bridgeAmount.value);
    if (!amount || amount <= 0) {
        feedbackError.value = 'Enter a valid amount';
        return;
    }

    if (!bothWalletsConnected.value) {
        feedbackError.value = 'Connect both wallets first';
        return;
    }

    if (amountExceedsBalance.value) {
        feedbackError.value = 'Insufficient balance';
        return;
    }

    bridgeProcessing.value = true;

    try {
        let txHash: string;
        let nonce: number;
        let senderAddress: string;
        let recipientAddress: string;

        if (bridgeDirection.value === 'evm_to_sol') {
            // EVM -> Solana: burn CYBER.sol on EVM to redeem on Solana
            senderAddress = evmWallet.address.value!;
            recipientAddress = solanaWallet.address.value!;

            const result = await bridge.redeemCyberSolOnEvm(
                bridgeAmount.value,
                recipientAddress,
            );
            if (!result) throw new Error('Transaction cancelled');
            txHash = result.txHash;
            nonce = result.nonce;
        } else {
            // Solana -> EVM: lock CYBER.sol SPL tokens on Solana bridge
            senderAddress = solanaWallet.address.value!;
            recipientAddress = evmWallet.address.value!;

            const result = await bridge.lockNativeOnSolana(
                bridgeAmount.value,
                recipientAddress,
            );
            if (!result) throw new Error('Transaction cancelled');
            txHash = result.txHash;
            nonce = result.nonce;
        }

        // Submit bridge request to backend relayer
        const csrfToken = document.cookie
            .match(/XSRF-TOKEN=([^;]+)/)?.[1];
        const response = await fetch('/bridge/submit', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken
                    ? decodeURIComponent(csrfToken)
                    : '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                direction: bridgeDirection.value,
                source_tx_hash: txHash,
                source_nonce: nonce,
                sender_address: senderAddress,
                recipient_address: recipientAddress,
                amount: bridgeAmount.value,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.message || 'Failed to register bridge request');
        }

        const br = data.bridge_request;
        if (br?.status === 'completed') {
            feedbackSuccess.value = `Bridge complete! ${amount} ${sourceTokenLabel.value} sent. Dest tx: ${br.destination_tx_hash?.slice(0, 12)}...`;
        } else if (br?.status === 'failed') {
            feedbackError.value = `Bridge failed: ${br.error_message || 'Unknown error'}`;
        } else {
            feedbackSuccess.value = `Bridge submitted. Tx: ${txHash.slice(0, 10)}...`;
        }

        bridgeAmount.value = '';
        router.reload();
        // Allow chain state to settle, then refresh on-chain balances
        setTimeout(() => refreshBalances(), 3000);
    } catch (err) {
        feedbackError.value =
            err instanceof Error ? err.message : 'Bridge transaction failed';
    } finally {
        bridgeProcessing.value = false;
    }
};

// --- EVM wallet: connect (client-side only) or attach (if authenticated) ---
const handleEvmConnect = async () => {
    feedbackError.value = null;
    feedbackSuccess.value = null;

    const address = await evmWallet.connect();
    if (!address) return;

    try {
        if (isAuthenticated.value) {
            const { nonce } = await walletAuth.generateNonce(address);
            const message = `Sign this message to link your wallet. Nonce: ${nonce}`;
            const signature = await evmWallet.signMessage(message);

            if (signature) {
                await walletAuth.attachEvmWallet(address, signature);
                feedbackSuccess.value = 'EVM wallet attached';
                router.reload();
            }
        }
        // Unauthenticated: wallet is already connected client-side, nothing else needed
    } catch (err) {
        feedbackError.value =
            err instanceof Error ? err.message : 'EVM wallet operation failed';
    }
};

// --- Solana wallet: connect (client-side only) or attach (if authenticated) ---
const handleSolanaConnect = async () => {
    feedbackError.value = null;
    feedbackSuccess.value = null;

    const address = await solanaWallet.connect();
    if (!address) return;

    try {
        if (isAuthenticated.value) {
            const { nonce } = await walletAuth.generateSolanaNonce(address);
            const message = `Sign this message to link your wallet. Nonce: ${nonce}`;
            const signature = await solanaWallet.signMessage(message);

            if (signature) {
                await walletAuth.attachSolanaWallet(address, signature);
                feedbackSuccess.value = 'Solana wallet attached';
                router.reload();
            }
        }
        // Unauthenticated: wallet is already connected client-side, nothing else needed
    } catch (err) {
        feedbackError.value =
            err instanceof Error
                ? err.message
                : 'Solana wallet operation failed';
    }
};

// --- Detach wallets ---
const handleEvmDetach = async () => {
    feedbackError.value = null;
    feedbackSuccess.value = null;
    try {
        await walletAuth.detachEvmWallet();
        evmWallet.disconnect();
        feedbackSuccess.value = 'EVM wallet detached';
        router.reload();
    } catch (err) {
        feedbackError.value =
            err instanceof Error ? err.message : 'Failed to detach EVM wallet';
    }
};

const handleSolanaDetach = async () => {
    feedbackError.value = null;
    feedbackSuccess.value = null;
    try {
        await walletAuth.detachSolanaWallet();
        solanaWallet.disconnect();
        feedbackSuccess.value = 'Solana wallet detached';
        router.reload();
    } catch (err) {
        feedbackError.value =
            err instanceof Error
                ? err.message
                : 'Failed to detach Solana wallet';
    }
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
            return 'text-[#706f6c] dark:text-[#A1A09A]';
    }
};
</script>

<template>
    <Head title="Cyberia Bridge">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>
    <div
        class="flex min-h-screen flex-col items-center bg-[#FDFDFC] p-6 text-[#1b1b18] lg:justify-center lg:p-8 dark:bg-[#0a0a0a]"
    >
        <header class="mb-6 w-full text-sm not-has-[nav]:hidden">
            <nav class="flex items-center justify-end gap-4">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboardUrl"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Dashboard
                </Link>
            </nav>
        </header>

        <div
            class="flex w-full max-w-lg flex-col items-center gap-6 opacity-100 transition-opacity duration-750 lg:grow starting:opacity-0"
        >
            <!-- Token price -->
            <div
                v-if="price"
                class="flex w-full items-center justify-between rounded-lg border border-[#19140035] px-5 py-3 dark:border-[#3E3E3A]"
            >
                <span
                    class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >CYBER</span
                >
                <div class="text-right">
                    <p
                        class="text-sm font-mono text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        {{ price.priceSol }} SOL
                    </p>
                    <p
                        class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        ${{ price.priceUsd }}
                    </p>
                </div>
            </div>

            <!-- Feedback messages -->
            <div
                v-if="feedbackError"
                class="w-full rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400 overflow-hidden"
            >
                {{ feedbackError }}
            </div>
            <div
                v-if="feedbackSuccess"
                class="w-full rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-600 dark:text-green-400"
            >
                {{ feedbackSuccess }}
            </div>

            <!-- Wallets -->
            <div class="flex w-full gap-3">
                <!-- EVM Wallet -->
                <div
                    class="flex flex-1 flex-wrap items-center gap-2 rounded-lg border border-[#19140035] p-3 dark:border-[#3E3E3A]"
                >
                    <Wallet
                        class="h-4 w-4 shrink-0 text-[#1b1b18] dark:text-[#EDEDEC]"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Metamask (EVM)
                        </p>
                        <p
                            v-if="
                                evmWallet.isConnected.value &&
                                evmWallet.address.value
                            "
                            class="truncate font-mono text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{
                                evmWallet.formatAddress(
                                    evmWallet.address.value,
                                )
                            }}
                        </p>
                        <p
                            v-if="evmWallet.isConnected.value && evmCyberSolDisplay"
                            class="mt-0.5 font-mono text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ evmCyberSolDisplay }} CYBER.sol
                        </p>
                    </div>
                    <button
                        v-if="evmWallet.isConnected.value && isAuthenticated && user?.wallet_address"
                        class="shrink-0 rounded p-1 text-red-500 hover:bg-red-500/10"
                        @click="handleEvmDetach"
                    >
                        <Unplug class="h-3 w-3" />
                    </button>
                    <button
                        v-else-if="evmWallet.isConnected.value"
                        class="shrink-0 rounded p-1 text-red-500 hover:bg-red-500/10"
                        @click="evmWallet.disconnect()"
                    >
                        <Unplug class="h-3 w-3" />
                    </button>
                    <button
                        v-if="!evmWallet.isConnected.value"
                        class="w-full shrink-0 rounded border border-[#19140035] px-2 py-1 text-xs text-[#1b1b18] hover:border-[#1915014a] disabled:opacity-50 sm:w-auto dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        :disabled="evmWallet.isConnecting.value"
                        @click="handleEvmConnect"
                    >
                        {{
                            evmWallet.isConnecting.value
                                ? '...'
                                : 'Connect'
                        }}
                    </button>
                </div>

                <!-- Solana Wallet -->
                <div
                    class="flex flex-1 flex-wrap items-center gap-2 rounded-lg border border-[#19140035] p-3 dark:border-[#3E3E3A]"
                >
                    <Wallet
                        class="h-4 w-4 shrink-0 text-[#1b1b18] dark:text-[#EDEDEC]"
                    />
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            Phantom (Solana)
                        </p>
                        <p
                            v-if="
                                solanaWallet.isConnected.value &&
                                solanaWallet.address.value
                            "
                            class="truncate font-mono text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{
                                solanaWallet.formatAddress(
                                    solanaWallet.address.value,
                                )
                            }}
                        </p>
                        <p
                            v-if="solanaWallet.isConnected.value && solCyberSolDisplay"
                            class="mt-0.5 font-mono text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                        >
                            {{ solCyberSolDisplay }} CYBER.sol
                        </p>
                    </div>
                    <button
                        v-if="solanaWallet.isConnected.value && isAuthenticated && user?.solana_wallet_address"
                        class="shrink-0 rounded p-1 text-red-500 hover:bg-red-500/10"
                        @click="handleSolanaDetach"
                    >
                        <Unplug class="h-3 w-3" />
                    </button>
                    <button
                        v-else-if="solanaWallet.isConnected.value"
                        class="shrink-0 rounded p-1 text-red-500 hover:bg-red-500/10"
                        @click="solanaWallet.disconnect()"
                    >
                        <Unplug class="h-3 w-3" />
                    </button>
                    <button
                        v-if="!solanaWallet.isConnected.value"
                        class="w-full shrink-0 rounded border border-[#19140035] px-2 py-1 text-xs text-[#1b1b18] hover:border-[#1915014a] disabled:opacity-50 sm:w-auto dark:border-[#3E3E3A] dark:text-[#EDEDEC]"
                        :disabled="solanaWallet.isConnecting.value"
                        @click="handleSolanaConnect"
                    >
                        {{
                            solanaWallet.isConnecting.value
                                ? '...'
                                : 'Connect'
                        }}
                    </button>
                </div>
            </div>

            <!-- Bridge Card -->
            <div
                class="w-full rounded-xl border border-[#19140035] p-5 dark:border-[#3E3E3A]"
            >
                <div class="mb-4 flex items-center gap-2">
                    <ArrowRightLeft
                        class="h-5 w-5 text-[#1b1b18] dark:text-[#EDEDEC]"
                    />
                    <h2
                        class="text-lg font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                    >
                        Bridge
                    </h2>
                </div>

                <!-- Source -->
                <div
                    class="rounded-lg bg-[#f5f5f4] p-4 dark:bg-[#1a1a1a]"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <span
                            class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >From</span
                        >
                        <div class="flex items-center gap-2">
                            <span
                                v-if="sourceBalance !== null"
                                class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                Balance:
                                <span class="font-mono">{{
                                    parseFloat(sourceBalance).toFixed(4)
                                }}</span>
                            </span>
                            <button
                                v-if="sourceBalance !== null && parseFloat(sourceBalance) > 0"
                                class="rounded bg-[#19140010] px-1.5 py-0.5 text-[10px] font-medium text-[#1b1b18] hover:bg-[#19140020] dark:bg-[#3E3E3A] dark:text-[#EDEDEC]"
                                @click="setMaxAmount"
                            >
                                MAX
                            </button>
                            <span
                                class="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                            >
                                {{ sourceLabel }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <input
                            v-model="bridgeAmount"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.0"
                            class="w-full bg-transparent text-2xl font-light text-[#1b1b18] outline-none placeholder:text-[#c4c4c0] dark:text-[#EDEDEC] dark:placeholder:text-[#555]"
                            :class="{ 'text-red-500 dark:text-red-400': amountExceedsBalance }"
                        />
                        <span
                            class="shrink-0 rounded-full bg-[#19140010] px-3 py-1 text-xs font-medium text-[#1b1b18] dark:bg-[#3E3E3A] dark:text-[#EDEDEC]"
                        >
                            {{ sourceTokenLabel }}
                        </span>
                    </div>
                    <p
                        v-if="amountExceedsBalance"
                        class="mt-1 text-xs text-red-500 dark:text-red-400"
                    >
                        Insufficient balance
                    </p>
                </div>

                <!-- Flip button -->
                <div class="flex justify-center -my-2 relative z-10">
                    <button
                        class="rounded-full border border-[#19140035] bg-[#FDFDFC] p-2 transition-transform hover:rotate-180 dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
                        @click="flipDirection"
                    >
                        <ArrowDown
                            class="h-4 w-4 text-[#1b1b18] dark:text-[#EDEDEC]"
                        />
                    </button>
                </div>

                <!-- Destination -->
                <div
                    class="bg-[#f5f5f4] p-4 dark:bg-[#1a1a1a]"
                    :class="bridgeFee ? 'rounded-t-lg' : 'rounded-lg'"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <span
                            class="text-xs text-[#706f6c] dark:text-[#A1A09A]"
                            >To</span
                        >
                        <span
                            class="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ destLabel }}
                        </span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span
                            class="text-2xl font-light text-[#1b1b18] dark:text-[#EDEDEC]"
                        >
                            {{ amountAfterFee ?? '0.0' }}
                        </span>
                        <span
                            class="shrink-0 rounded-full bg-[#19140010] px-3 py-1 text-xs font-medium text-[#1b1b18] dark:bg-[#3E3E3A] dark:text-[#EDEDEC]"
                        >
                            {{ destTokenLabel }}
                        </span>
                    </div>
                </div>

                <!-- Fee -->
                <div
                    v-if="bridgeFee"
                    class="flex items-center justify-between rounded-b-lg bg-[#19140008] px-4 py-2 dark:bg-[#1a1a17]"
                >
                    <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        Bridge fee (1%)
                    </span>
                    <span class="text-xs text-[#706f6c] dark:text-[#A1A09A]">
                        {{ bridgeFee }} {{ sourceTokenLabel }}
                    </span>
                </div>

                <!-- Submit -->
                <button
                    class="mt-4 w-full rounded-lg bg-[#1b1b18] py-3 text-sm font-medium text-white transition-colors hover:bg-[#2d2d2a] disabled:opacity-50 dark:bg-[#EDEDEC] dark:text-[#0a0a0a] dark:hover:bg-[#d4d4d0]"
                    :disabled="
                        bridgeProcessing ||
                        !bothWalletsConnected ||
                        !bridgeAmount ||
                        amountExceedsBalance
                    "
                    @click="handleBridgeSubmit"
                >
                    <span
                        v-if="bridgeProcessing"
                        class="flex items-center justify-center gap-2"
                    >
                        <Loader2 class="h-4 w-4 animate-spin" />
                        Processing...
                    </span>
                    <span v-else-if="!bothWalletsConnected">
                        Connect both wallets
                    </span>
                    <span v-else-if="!bridgeAmount">
                        Enter amount
                    </span>
                    <span v-else-if="amountExceedsBalance">
                        Insufficient balance
                    </span>
                    <span v-else> Bridge {{ sourceTokenLabel }} </span>
                </button>
            </div>

            <!-- Bridge History -->
            <div
                v-if="bridgeHistory.length > 0"
                class="w-full rounded-xl border border-[#19140035] p-5 dark:border-[#3E3E3A]"
            >
                <h3
                    class="mb-3 text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]"
                >
                    History
                </h3>
                <div class="flex flex-col gap-2">
                    <div
                        v-for="item in bridgeHistory"
                        :key="item.id"
                        class="flex items-center justify-between rounded-lg bg-[#f5f5f4] px-4 py-3 dark:bg-[#1a1a1a]"
                    >
                        <div class="min-w-0 flex-1">
                            <div
                                class="flex items-center gap-2"
                            >
                                <span
                                    class="text-xs font-medium text-[#1b1b18] dark:text-[#EDEDEC]"
                                >
                                    {{
                                        item.direction ===
                                        'sol_to_evm'
                                            ? 'SOL -> EVM'
                                            : 'EVM -> SOL'
                                    }}
                                </span>
                                <span
                                    class="font-mono text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                >
                                    {{ item.amount }}
                                </span>
                            </div>
                            <p
                                class="mt-0.5 truncate font-mono text-[10px] text-[#706f6c] dark:text-[#A1A09A]"
                            >
                                {{ formatAddr(item.sender_address) }}
                                ->
                                {{
                                    formatAddr(
                                        item.recipient_address,
                                    )
                                }}
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
</template>
