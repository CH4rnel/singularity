<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    JsonRpcProvider,
    formatEther,
    parseEther,
} from 'ethers';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ConfigWarning from '@/components/web3/ConfigWarning.vue';
import PageHero from '@/components/web3/PageHero.vue';
import { useWallet } from '@/composables/useWallet';
import { getSelectedEvmProvider } from '@/lib/evmProvider';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc0fe';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

const env =
    (import.meta as { env?: Record<string, string | undefined> }).env ?? {};
// Baked-in production deploy (deployments/cyberia-predictions.json); the env
// var only overrides it for staging/redeploys.
const PREDICTIONS_CONTRACT =
    env.VITE_PREDICTIONS_CONTRACT ||
    '0xb88063Cb2db16473Fb6deB71BaE364aFd09fdE54';

// Mirrors PredictionMarket.RESOLVE_WINDOW: unresolved markets refund after it.
const RESOLVE_WINDOW = 30 * 24 * 3600;

// enum Outcome { None, Yes, No, Invalid }
const OUTCOME_NONE = 0;
const OUTCOME_YES = 1;
const OUTCOME_INVALID = 3;

const PREDICTIONS_ABI = [
    'function marketCount() view returns (uint256)',
    'function minBet() view returns (uint256)',
    'function createFee() view returns (uint256)',
    'function owner() view returns (address)',
    'function getMarkets(uint256 offset, uint256 limit) view returns (tuple(uint256 id, address creator, string question, uint64 closeTime, uint64 resolvedAt, uint8 outcome, uint256 yesPool, uint256 noPool, uint256 feePaid)[])',
    'function getUserBets(address user, uint256 offset, uint256 limit) view returns (uint256[] yesBets, uint256[] noBets, bool[] claimedArr, uint256[] claimable)',
    'function createMarket(string question, uint64 closeTime) payable returns (uint256)',
    'function bet(uint256 id, bool yes) payable',
    'function claim(uint256 id)',
];

type MarketRow = {
    id: number;
    creator: string;
    question: string;
    closeTime: number;
    resolvedAt: number;
    outcome: number;
    yesPool: bigint;
    noPool: bigint;
    userYes: bigint;
    userNo: bigint;
    claimable: bigint;
};

const wallet = useWallet();
const page = usePage();
const authUser = computed(
    () =>
        page.props.auth?.user as { wallet_address?: string | null } | undefined,
);

const markets = ref<MarketRow[]>([]);
const minBet = ref<bigint>(10n ** 15n);
const createFee = ref<bigint>(0n);
const oracle = ref<string>('');
const loading = ref(false);
const busyMarket = ref<number | null>(null);
const creating = ref(false);
const status = ref<string | null>(null);
const error = ref<string | null>(null);
const amounts = ref<Record<number, string>>({});
const newQuestion = ref('');
const newCloseAt = ref('');
const nowSec = ref(Math.floor(Date.now() / 1000));

const isConfigured = computed(() => !!PREDICTIONS_CONTRACT);
const userAddress = computed(
    () => wallet.address.value || authUser.value?.wallet_address || null,
);

const readRpcUrl =
    typeof window !== 'undefined'
        ? window.location.origin + CYBERIA_RPC
        : CYBERIA_PUBLIC_RPC;
const readProvider = new JsonRpcProvider(readRpcUrl, {
    chainId: CYBERIA_CHAIN_ID,
    name: 'cyberia',
});

const ensureCyberiaNetwork = async (): Promise<BrowserProvider> => {
    const eth = getSelectedEvmProvider();

    if (!eth) {
        throw new Error('EVM wallet not found');
    }

    const provider = new BrowserProvider(eth);
    const net = await provider.getNetwork();

    if (Number(net.chainId) !== CYBERIA_CHAIN_ID) {
        try {
            await eth.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: CYBERIA_CHAIN_ID_HEX }],
            });
        } catch (e) {
            if ((e as { code?: number }).code === 4902) {
                await eth.request({
                    method: 'wallet_addEthereumChain',
                    params: [
                        {
                            chainId: CYBERIA_CHAIN_ID_HEX,
                            chainName: 'Cyberia',
                            nativeCurrency: {
                                name: 'Cyber',
                                symbol: 'CYBER',
                                decimals: 18,
                            },
                            rpcUrls: [CYBERIA_PUBLIC_RPC, CYBERIA_RPC],
                        },
                    ],
                });
            } else {
                throw e;
            }
        }

        return new BrowserProvider(eth);
    }

    return provider;
};

const loadMarkets = async (): Promise<void> => {
    if (!isConfigured.value) {
        return;
    }

    loading.value = true;

    try {
        const c = new Contract(
            PREDICTIONS_CONTRACT,
            PREDICTIONS_ABI,
            readProvider,
        );
        const [rawCount, min, fee, own] = await Promise.all([
            c.marketCount(),
            c.minBet(),
            c.createFee(),
            c.owner(),
        ]);
        const count = Number(rawCount);
        minBet.value = min as bigint;
        createFee.value = fee as bigint;
        oracle.value = (own as string).toLowerCase();

        if (count === 0) {
            markets.value = [];
            error.value = null;

            return;
        }

        const raw = await c.getMarkets(0, count);

        let userYes: bigint[] = [];
        let userNo: bigint[] = [];
        let claimable: bigint[] = [];

        if (userAddress.value) {
            const bets = await c.getUserBets(userAddress.value, 0, count);
            userYes = [...(bets[0] as bigint[])];
            userNo = [...(bets[1] as bigint[])];
            claimable = [...(bets[3] as bigint[])];
        }

        markets.value = (
            raw as Array<
                [
                    bigint,
                    string,
                    string,
                    bigint,
                    bigint,
                    bigint,
                    bigint,
                    bigint,
                    bigint,
                ]
            >
        ).map((m, i) => ({
            id: Number(m[0]),
            creator: m[1],
            question: m[2],
            closeTime: Number(m[3]),
            resolvedAt: Number(m[4]),
            outcome: Number(m[5]),
            yesPool: m[6],
            noPool: m[7],
            userYes: userYes[i] ?? 0n,
            userNo: userNo[i] ?? 0n,
            claimable: claimable[i] ?? 0n,
        }));
        error.value = null;
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        loading.value = false;
    }
};

const isOpen = (m: MarketRow): boolean =>
    m.outcome === OUTCOME_NONE && nowSec.value < m.closeTime;

const activeMarkets = computed(() =>
    markets.value.filter((m) => m.outcome === OUTCOME_NONE).reverse(),
);
const resolvedMarkets = computed(() =>
    markets.value.filter((m) => m.outcome !== OUTCOME_NONE).reverse(),
);
const totalVolume = computed(() =>
    markets.value.reduce((acc, m) => acc + m.yesPool + m.noPool, 0n),
);

const statusOf = (
    m: MarketRow,
): { label: string; variant: 'default' | 'secondary' | 'destructive' } => {
    if (m.outcome === OUTCOME_YES) {
        return { label: 'Resolved: YES', variant: 'default' };
    }

    if (m.outcome === 2) {
        return { label: 'Resolved: NO', variant: 'destructive' };
    }

    if (m.outcome === OUTCOME_INVALID) {
        return { label: 'Cancelled — refunds', variant: 'secondary' };
    }

    if (nowSec.value < m.closeTime) {
        return {
            label: `Open — closes in ${relTime(m.closeTime - nowSec.value)}`,
            variant: 'default',
        };
    }

    if (nowSec.value <= m.closeTime + RESOLVE_WINDOW) {
        return { label: 'Awaiting oracle', variant: 'secondary' };
    }

    return { label: 'Unresolved — refunds', variant: 'secondary' };
};

const relTime = (seconds: number): string => {
    if (seconds < 3600) {
        return `${Math.max(1, Math.floor(seconds / 60))}m`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
    }

    return `${Math.floor(seconds / 86400)}d ${Math.floor((seconds % 86400) / 3600)}h`;
};

const yesPercent = (m: MarketRow): number => {
    const total = m.yesPool + m.noPool;

    if (total === 0n) {
        return 50;
    }

    return Number((m.yesPool * 1000n) / total) / 10;
};

const creatorLabel = (m: MarketRow): string => {
    if (m.creator.toLowerCase() === oracle.value) {
        return 'oracle';
    }

    return `${m.creator.slice(0, 6)}…${m.creator.slice(-4)}`;
};

const fmt = (v: bigint): string => {
    const n = Number(formatEther(v));

    if (n === 0) {
        return '0';
    }

    if (n < 0.0001) {
        return '<0.0001';
    }

    return n.toLocaleString('en', { maximumFractionDigits: 4 });
};

const createMarket = async (): Promise<void> => {
    if (creating.value || busyMarket.value !== null) {
        return;
    }

    error.value = null;

    const question = newQuestion.value.trim();

    if (!question) {
        error.value = 'Enter a question';

        return;
    }

    const closeSec = Math.floor(new Date(newCloseAt.value).getTime() / 1000);

    if (!Number.isFinite(closeSec) || closeSec <= nowSec.value) {
        error.value = 'Close time must be in the future';

        return;
    }

    if (!wallet.isConnected.value) {
        await wallet.connect();

        if (!wallet.isConnected.value) {
            return;
        }
    }

    creating.value = true;
    status.value = 'Confirm createMarket() in your wallet…';

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const c = new Contract(PREDICTIONS_CONTRACT, PREDICTIONS_ABI, signer);
        const [liveFee, liveOwner] = await Promise.all([
            c.createFee() as Promise<bigint>,
            c.owner() as Promise<string>,
        ]);
        createFee.value = liveFee;
        oracle.value = liveOwner.toLowerCase();
        // The oracle creates for free; everyone else pays exactly createFee.
        const value =
            (await signer.getAddress()).toLowerCase() === liveOwner.toLowerCase()
                ? 0n
                : liveFee;
        const tx = await c.createMarket(question, closeSec, { value });
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Market created: "${question}".`;
        newQuestion.value = '';
        await loadMarkets();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
    } finally {
        creating.value = false;
    }
};

const placeBet = async (m: MarketRow, yes: boolean): Promise<void> => {
    if (busyMarket.value !== null) {
        return;
    }

    error.value = null;

    let value: bigint;

    try {
        value = parseEther(amounts.value[m.id] || '1');
    } catch {
        error.value = 'Enter a valid CYBER amount';

        return;
    }

    if (value < minBet.value) {
        error.value = `Minimum bet is ${fmt(minBet.value)} CYBER`;

        return;
    }

    if (!wallet.isConnected.value) {
        await wallet.connect();

        if (!wallet.isConnected.value) {
            return;
        }
    }

    busyMarket.value = m.id;
    status.value = `Confirm the ${yes ? 'YES' : 'NO'} bet in your wallet…`;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const c = new Contract(PREDICTIONS_CONTRACT, PREDICTIONS_ABI, signer);
        const tx = await c.bet(m.id, yes, { value });
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Bet ${fmt(value)} CYBER on ${yes ? 'YES' : 'NO'} — #${m.id}.`;
        await loadMarkets();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
    } finally {
        busyMarket.value = null;
    }
};

const claim = async (m: MarketRow): Promise<void> => {
    if (busyMarket.value !== null) {
        return;
    }

    error.value = null;

    if (!wallet.isConnected.value) {
        await wallet.connect();

        if (!wallet.isConnected.value) {
            return;
        }
    }

    busyMarket.value = m.id;
    status.value = 'Confirm claim() in your wallet…';

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const c = new Contract(PREDICTIONS_CONTRACT, PREDICTIONS_ABI, signer);
        const tx = await c.claim(m.id);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Claimed ${fmt(m.claimable)} CYBER from #${m.id}.`;
        await loadMarkets();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
        status.value = null;
    } finally {
        busyMarket.value = null;
    }
};

let poll: ReturnType<typeof setInterval> | null = null;
let clock: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    // Default the new-market close time to a week out, in the local timezone
    // format that <input type="datetime-local"> expects.
    const week = new Date(Date.now() + 7 * 86400_000);
    week.setMinutes(week.getMinutes() - week.getTimezoneOffset());
    newCloseAt.value = week.toISOString().slice(0, 16);

    void loadMarkets();
    poll = setInterval(() => {
        if (busyMarket.value === null) {
            void loadMarkets();
        }
    }, 10000);
    clock = setInterval(() => {
        nowSec.value = Math.floor(Date.now() / 1000);
    }, 1000);
});

onUnmounted(() => {
    if (poll) {
        clearInterval(poll);
    }

    if (clock) {
        clearInterval(clock);
    }
});
</script>

<template>
    <Head title="Cyberia Predictions" />

    <div>
        <div class="mx-auto max-w-3xl px-4 py-6 pb-16">
            <PageHero
                title="Predictions"
                description="Parimutuel YES/NO markets on Cyberia. Stake native CYBER on an outcome; when the oracle resolves, winners split the whole pot pro-rata (2% fee off the losing pool). Markets left unresolved for 30 days refund everyone."
            />

            <ConfigWarning v-if="!isConfigured" class="mt-4">
                Set <code>VITE_PREDICTIONS_CONTRACT</code> in
                <code>.env</code> and rebuild the frontend.
            </ConfigWarning>

            <template v-else>
                <div
                    class="mt-4 flex flex-wrap gap-4 text-sm text-muted-foreground"
                >
                    <span>markets: {{ markets.length }}</span>
                    <span>volume: {{ fmt(totalVolume) }} CYBER</span>
                    <span v-if="loading">syncing…</span>
                </div>

                <p v-if="status" class="mt-3 text-sm">{{ status }}</p>
                <p v-if="error" class="mt-3 text-sm text-destructive">
                    {{ error }}
                </p>

                <section class="mt-6 rounded-lg border p-4">
                    <h2 class="text-lg font-semibold">Create a market</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Anyone can open a market
                        <template v-if="createFee > 0n"
                            >for {{ fmt(createFee) }} CYBER</template
                        ><template v-else>for free</template>; the oracle
                        resolves it after close.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <Input
                            v-model="newQuestion"
                            placeholder="Will X happen by Y?"
                            maxlength="200"
                            class="min-w-52 flex-1"
                            :disabled="creating"
                        />
                        <Input
                            v-model="newCloseAt"
                            type="datetime-local"
                            class="w-52"
                            :disabled="creating"
                        />
                        <Button
                            size="sm"
                            :disabled="creating || busyMarket !== null"
                            @click="createMarket"
                        >
                            {{
                                createFee > 0n
                                    ? `Create — ${fmt(createFee)} CYBER`
                                    : 'Create'
                            }}
                        </Button>
                    </div>
                </section>

                <section v-if="activeMarkets.length" class="mt-6">
                    <h2 class="text-lg font-semibold">Active</h2>
                    <div class="mt-3 space-y-4">
                        <div
                            v-for="m in activeMarkets"
                            :key="m.id"
                            class="rounded-lg border p-4"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <p class="font-medium">
                                    <span class="text-muted-foreground"
                                        >#{{ m.id }}</span
                                    >
                                    {{ m.question }}
                                </p>
                                <Badge :variant="statusOf(m).variant">{{
                                    statusOf(m).label
                                }}</Badge>
                            </div>

                            <div
                                class="mt-3 h-2 overflow-hidden rounded bg-red-900/60"
                            >
                                <div
                                    class="h-full bg-emerald-600"
                                    :style="{ width: yesPercent(m) + '%' }"
                                />
                            </div>
                            <div
                                class="mt-1 flex justify-between text-xs text-muted-foreground"
                            >
                                <span
                                    >YES {{ yesPercent(m).toFixed(1) }}% ·
                                    {{ fmt(m.yesPool) }} CYBER</span
                                >
                                <span
                                    >NO {{ (100 - yesPercent(m)).toFixed(1) }}%
                                    · {{ fmt(m.noPool) }} CYBER</span
                                >
                            </div>

                            <p class="mt-1 text-xs text-muted-foreground">
                                by {{ creatorLabel(m) }}
                            </p>

                            <p
                                v-if="m.userYes > 0n || m.userNo > 0n"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                Your stake:
                                <template v-if="m.userYes > 0n"
                                    >{{ fmt(m.userYes) }} on YES
                                </template>
                                <template v-if="m.userNo > 0n"
                                    >{{ fmt(m.userNo) }} on NO</template
                                >
                            </p>

                            <div
                                v-if="isOpen(m)"
                                class="mt-3 flex flex-wrap items-center gap-2"
                            >
                                <Input
                                    v-model="amounts[m.id]"
                                    placeholder="1"
                                    inputmode="decimal"
                                    class="w-28"
                                    :disabled="busyMarket !== null"
                                />
                                <span class="text-xs text-muted-foreground"
                                    >CYBER</span
                                >
                                <Button
                                    size="sm"
                                    class="bg-emerald-600 hover:bg-emerald-500"
                                    :disabled="busyMarket !== null"
                                    @click="placeBet(m, true)"
                                >
                                    Bet YES
                                </Button>
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    :disabled="busyMarket !== null"
                                    @click="placeBet(m, false)"
                                >
                                    Bet NO
                                </Button>
                            </div>

                            <Button
                                v-if="m.claimable > 0n"
                                size="sm"
                                class="mt-3"
                                :disabled="busyMarket !== null"
                                @click="claim(m)"
                            >
                                Claim {{ fmt(m.claimable) }} CYBER
                            </Button>
                        </div>
                    </div>
                </section>

                <p
                    v-else-if="!loading"
                    class="mt-6 text-sm text-muted-foreground"
                >
                    No active markets — create the first one above.
                </p>

                <section v-if="resolvedMarkets.length" class="mt-8">
                    <h2 class="text-lg font-semibold">Resolved</h2>
                    <div class="mt-3 space-y-4">
                        <div
                            v-for="m in resolvedMarkets"
                            :key="m.id"
                            class="rounded-lg border p-4 opacity-90"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <p class="font-medium">
                                    <span class="text-muted-foreground"
                                        >#{{ m.id }}</span
                                    >
                                    {{ m.question }}
                                </p>
                                <Badge :variant="statusOf(m).variant">{{
                                    statusOf(m).label
                                }}</Badge>
                            </div>

                            <p class="mt-2 text-xs text-muted-foreground">
                                Pot {{ fmt(m.yesPool + m.noPool) }} CYBER (YES
                                {{ fmt(m.yesPool) }} / NO {{ fmt(m.noPool) }})
                                <template v-if="m.userYes > 0n || m.userNo > 0n"
                                    >— your stake
                                    {{ fmt(m.userYes + m.userNo) }}</template
                                >
                            </p>

                            <Button
                                v-if="m.claimable > 0n"
                                size="sm"
                                class="mt-3"
                                :disabled="busyMarket !== null"
                                @click="claim(m)"
                            >
                                Claim {{ fmt(m.claimable) }} CYBER
                            </Button>
                        </div>
                    </div>
                </section>

                <div class="mt-6">
                    <Button
                        v-if="!wallet.isConnected.value"
                        variant="outline"
                        size="sm"
                        @click="wallet.connect()"
                    >
                        Connect wallet
                    </Button>
                </div>
            </template>
        </div>
    </div>
</template>
