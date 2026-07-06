<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    JsonRpcProvider,
    formatEther,
    parseEther,
} from 'ethers';
import {
    ArrowDown,
    ArrowRight,
    ExternalLink,
    Fuel,
    Landmark,
    Loader2,
    RefreshCw,
    ShieldCheck,
    Sparkles,
    Vote,
    Wallet,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWallet } from '@/composables/useWallet';
import { getMetaMaskProvider } from '@/lib/evmProvider';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc0fe';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

// Two fixed-rate redeemers convert bridged CYBER.sol -> native CYBER at 1000 : 1.
// They share the same swap logic and payout mechanism (native balance held by
// the contract) and differ only in what happens to the CYBER.sol you pay in:
//  - standard: the contract keeps it (the owner can withdraw it later).
//  - burn:     it is forwarded straight to 0x…dEaD and removed from supply.
// The user picks which contract to redeem through via the toggle on the card.
const SWAP_CONTRACTS = {
    standard: {
        address: '0x69b1614B088F5670E49bcC6fE33F28F2544F7415',
        label: 'Standard',
        burns: false,
    },
    burn: {
        address: '0xa5Ae36E5b1eDb24BCa2F96783d079B28e0BCfd71',
        label: 'Burn 🔥',
        burns: true,
    },
} as const;
type SwapMode = keyof typeof SWAP_CONTRACTS;
const swapModes = Object.keys(SWAP_CONTRACTS) as SwapMode[];

const CYBER_SOL_ADDRESS = '0x7DcDa19Cf984ca708E5fA228AC148e7d82D508BA';
const RATE = 1000n;

// Native value transfers to the contract carry empty calldata, and the Cyberia
// node estimates them at the 21000 EOA-transfer minimum — but receive() emits an
// event and needs more, so an estimated tx reverts out-of-gas. Send funding with
// an explicit gas limit to avoid that.
const FUND_GAS_LIMIT = 100000n;

const SWAP_ABI = [
    'function swap(uint256 amountIn)',
    'function quote(uint256 amountIn) view returns (uint256)',
    'function cyberSol() view returns (address)',
    'function RATE() view returns (uint256)',
    'function owner() view returns (address)',
    'function withdrawNative(address to, uint256 amount)',
    // standard converter only — the burn converter holds no tokens to rescue:
    'function withdrawTokens(address to, uint256 amount)',
    // burn converter only — cumulative CYBER.sol forwarded to 0x…dEaD:
    'function totalBurned() view returns (uint256)',
];

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];

const wallet = useWallet();

// Which redeemer the user is interacting with. Drives every contract call,
// balance read and the owner panel below.
const mode = ref<SwapMode>('standard');
const activeSwap = computed(() => SWAP_CONTRACTS[mode.value]);
const swapAddress = computed(() => activeSwap.value.address);

const page = usePage();
const authUser = computed(
    () =>
        page.props.auth?.user as { wallet_address?: string | null } | undefined,
);

const readRpcUrl =
    typeof window !== 'undefined'
        ? window.location.origin + CYBERIA_RPC
        : CYBERIA_PUBLIC_RPC;
const readProvider = new JsonRpcProvider(readRpcUrl, {
    chainId: CYBERIA_CHAIN_ID,
    name: 'cyberia',
});

// Contract state
const liquidity = ref<bigint>(0n); // native CYBER held by the contract
const collected = ref<bigint>(0n); // CYBER.sol collected by the contract
const owner = ref<string | null>(null);

// Connected-wallet state
const myNative = ref<bigint>(0n);
const myCyberSol = ref<bigint>(0n);
const myAllowance = ref<bigint>(0n);

const loading = ref(false);
const status = ref<string | null>(null);
const error = ref<string | null>(null);

const isOwner = computed(
    () =>
        !!wallet.address.value &&
        !!owner.value &&
        wallet.address.value.toLowerCase() === owner.value.toLowerCase(),
);

const hasWallet = computed(() => !!wallet.address.value);

const shortAddr = (a: string): string => `${a.slice(0, 6)}…${a.slice(-4)}`;

const fmt = (v: bigint, digits = 6): string => {
    const s = formatEther(v);
    const [int, dec = ''] = s.split('.');

    return dec ? `${int}.${dec.slice(0, digits)}` : int;
};

// <input type="number"> with v-model can deliver a JS number; parseEther in
// ethers v6 strictly requires a string. Normalize before parsing.
const toWei = (v: unknown): bigint => parseEther(String(v ?? '').trim() || '0');

async function loadState(): Promise<void> {
    loading.value = true;

    try {
        const addr = swapAddress.value;
        const swap = new Contract(addr, SWAP_ABI, readProvider);
        const token = new Contract(CYBER_SOL_ADDRESS, ERC20_ABI, readProvider);

        // For the burn converter "collected" is the cumulative amount burned
        // (its own token balance is always ~0); for the standard one it is the
        // CYBER.sol currently held by the contract.
        const [bal, coll, own] = await Promise.all([
            readProvider.getBalance(addr),
            (activeSwap.value.burns
                ? swap.totalBurned()
                : token.balanceOf(addr)) as Promise<bigint>,
            swap.owner() as Promise<string>,
        ]);
        liquidity.value = bal;
        collected.value = coll;
        owner.value = own;

        const me = wallet.address.value;

        if (me) {
            const [n, c, a] = await Promise.all([
                readProvider.getBalance(me),
                token.balanceOf(me) as Promise<bigint>,
                token.allowance(me, addr) as Promise<bigint>,
            ]);
            myNative.value = n;
            myCyberSol.value = c;
            myAllowance.value = a;
        }
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        loading.value = false;
    }
}

const ensureCyberiaNetwork = async (): Promise<BrowserProvider> => {
    const eth = getMetaMaskProvider();

    if (!eth) {
throw new Error('MetaMask not found');
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

// ---- Fund liquidity (send native CYBER to the contract) -------------------
const fundAmount = ref('');
const fundBusy = ref(false);

async function fundLiquidity(): Promise<void> {
    error.value = null;
    let value: bigint;

    try {
        value = toWei(fundAmount.value);
    } catch {
        error.value = 'Invalid amount';

        return;
    }

    if (value <= 0n) {
        error.value = 'Amount must be greater than 0';

        return;
    }

    fundBusy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        status.value = 'Confirm the funding transfer in your wallet…';
        const tx = await signer.sendTransaction({
            to: swapAddress.value,
            value,
            gasLimit: FUND_GAS_LIMIT,
        });
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Funded ${fundAmount.value} CYBER.`;
        fundAmount.value = '';
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        fundBusy.value = false;
    }
}

// ---- Swap CYBER.sol -> CYBER ----------------------------------------------
const swapAmount = ref('');
const swapBusy = ref(false);

// amountIn rounded down to an exact multiple of RATE (swap() requires it).
const swapAmountIn = computed<bigint>(() => {
    try {
        const raw = toWei(swapAmount.value);

        return (raw / RATE) * RATE;
    } catch {
        return 0n;
    }
});
const swapAmountOut = computed<bigint>(() => swapAmountIn.value / RATE);

const swapReady = computed(
    () =>
        swapAmountIn.value > 0n &&
        swapAmountIn.value <= myCyberSol.value &&
        swapAmountOut.value <= liquidity.value,
);

// Set the swap input from a fraction of the connected wallet's CYBER.sol
// balance. The amount is later rounded down to a multiple of RATE in swap().
const setSwapFraction = (numerator: bigint, denominator: bigint): void => {
    const part = (myCyberSol.value * numerator) / denominator;
    swapAmount.value = part > 0n ? formatEther(part) : '';
};

const connecting = ref(false);

async function connectWallet(): Promise<void> {
    error.value = null;
    connecting.value = true;

    try {
        await wallet.connect();
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        connecting.value = false;
    }
}

async function doSwap(): Promise<void> {
    error.value = null;
    const amountIn = swapAmountIn.value;

    if (amountIn <= 0n) {
        error.value = 'Amount too small (min 1000 wei of CYBER.sol)';

        return;
    }

    const amountOut = amountIn / RATE;

    if (amountIn > myCyberSol.value) {
        error.value = 'Insufficient CYBER.sol balance';

        return;
    }

    if (amountOut > liquidity.value) {
        error.value = `Contract has only ${fmt(liquidity.value)} CYBER liquidity (need ${fmt(amountOut)})`;

        return;
    }

    swapBusy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();

        if (myAllowance.value < amountIn) {
            status.value = 'Approving CYBER.sol…';
            const token = new Contract(CYBER_SOL_ADDRESS, ERC20_ABI, signer);
            const atx = await token.approve(swapAddress.value, amountIn);
            await atx.wait();
        }

        status.value = 'Confirm the swap in your wallet…';
        const swap = new Contract(swapAddress.value, SWAP_ABI, signer);
        const tx = await swap.swap(amountIn);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Swapped ${fmt(amountIn)} CYBER.sol to ${fmt(amountOut)} CYBER.`;
        swapAmount.value = '';
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        swapBusy.value = false;
    }
}

// ---- Owner: withdraw liquidity / collected tokens ------------------------
const wNativeAmount = ref('');
const wNativeTo = ref('');
const wNativeBusy = ref(false);
const wTokenAmount = ref('');
const wTokenTo = ref('');
const wTokenBusy = ref(false);

async function withdrawNative(): Promise<void> {
    error.value = null;
    let amount: bigint;

    try {
        amount = toWei(wNativeAmount.value);
    } catch {
        error.value = 'Invalid amount';

        return;
    }

    if (amount <= 0n) {
        error.value = 'Amount must be greater than 0';

        return;
    }

    wNativeBusy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const to = wNativeTo.value.trim() || (await signer.getAddress());
        const swap = new Contract(swapAddress.value, SWAP_ABI, signer);
        status.value = 'Withdrawing CYBER…';
        const tx = await swap.withdrawNative(to, amount);
        await tx.wait();
        status.value = `Withdrew ${wNativeAmount.value} CYBER to ${to}.`;
        wNativeAmount.value = '';
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        wNativeBusy.value = false;
    }
}

async function withdrawTokens(): Promise<void> {
    error.value = null;
    let amount: bigint;

    try {
        amount = toWei(wTokenAmount.value);
    } catch {
        error.value = 'Invalid amount';

        return;
    }

    if (amount <= 0n) {
        error.value = 'Amount must be greater than 0';

        return;
    }

    wTokenBusy.value = true;

    try {
        const provider = await ensureCyberiaNetwork();
        const signer = await provider.getSigner();
        const to = wTokenTo.value.trim() || (await signer.getAddress());
        const swap = new Contract(swapAddress.value, SWAP_ABI, signer);
        status.value = 'Withdrawing CYBER.sol…';
        const tx = await swap.withdrawTokens(to, amount);
        await tx.wait();
        status.value = `Withdrew ${wTokenAmount.value} CYBER.sol to ${to}.`;
        wTokenAmount.value = '';
        await loadState();
    } catch (e) {
        error.value = (e as Error).message ?? String(e);
    } finally {
        wTokenBusy.value = false;
    }
}

const explorerUrl = (addr: string): string =>
    `https://explorer.cyberia.church/address/${addr}`;

onMounted(async () => {
    // This page renders with no shared layout, so AppSidebar's wallet
    // restore() never runs here. Reconnect the saved/authorized wallet
    // ourselves — otherwise wallet.address stays null, isOwner is false and
    // the owner controls never appear. Mirrors Bridge/Lending/Liquidate.
    await wallet.restore(authUser.value?.wallet_address ?? null);
    await loadState();
});
// Reload balances once a wallet connects / switches account.
watch(() => wallet.address.value, loadState);
// Switching converter: re-read its liquidity/allowance and clear stale messages.
watch(mode, () => {
    myAllowance.value = 0n;
    status.value = null;
    error.value = null;
    loadState();
});
</script>

<template>
    <Head title="Bring your CYBER home · CYBER.sol redeemer" />

    <main class="relative overflow-hidden">
        <!-- ambient gradient glow -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-x-0 -top-40 -z-10 flex justify-center"
        >
            <div
                class="h-[28rem] w-[60rem] max-w-full rounded-full bg-gradient-to-tr from-cyan-500/20 via-fuchsia-500/10 to-teal-300/20 blur-3xl"
            ></div>
        </div>

        <div class="mx-auto max-w-6xl space-y-16 px-4 py-12 sm:py-16">
            <!-- HERO -->
            <section class="space-y-6 text-center">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-border bg-background/60 px-3 py-1 text-xs font-medium text-muted-foreground backdrop-blur"
                >
                    <Sparkles class="h-3.5 w-3.5 text-violet-500" />
                    Solana
                    <ArrowRight class="h-3 w-3" />
                    Cyberia
                </span>
                <h1 class="text-4xl font-bold tracking-tight sm:text-5xl">
                    Bring your
                    <span
                        class="bg-gradient-to-r from-cyan-400 via-fuchsia-500 to-teal-300 bg-clip-text text-transparent"
                        >CYBER</span
                    >
                    home
                </h1>
                <p class="mx-auto max-w-2xl text-base text-muted-foreground">
                    Redeem bridged CYBER.sol for native CYBER — the coin that
                    pays for gas, votes in the DAO, powers lending and trades on
                    the DEX. Fixed rate, zero slippage, and liquidity that can't
                    be pulled.
                </p>
                <div class="flex flex-wrap items-center justify-center gap-3">
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-border px-3 py-1.5 text-sm font-medium"
                    >
                        <span class="text-muted-foreground">Rate</span>
                        <span class="font-mono">1000 : 1</span>
                    </span>
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-emerald-500/40 bg-emerald-500/5 px-3 py-1.5 text-sm font-medium"
                    >
                        <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                        <span class="font-mono">{{ fmt(liquidity, 2) }}</span>
                        CYBER ready to redeem
                    </span>
                </div>
            </section>

            <!-- SWAP CARD -->
            <section class="mx-auto w-full max-w-md space-y-4">
                <div
                    class="rounded-3xl bg-gradient-to-br from-cyan-400/40 via-border to-teal-300/40 p-px shadow-xl"
                >
                    <div
                        class="space-y-3 rounded-[calc(1.5rem-1px)] bg-card p-5"
                    >
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-semibold">Redeem</h2>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 text-xs text-muted-foreground transition hover:text-foreground disabled:opacity-50"
                                :disabled="loading"
                                @click="loadState"
                            >
                                <RefreshCw
                                    class="h-3.5 w-3.5"
                                    :class="loading && 'animate-spin'"
                                />
                                Refresh
                            </button>
                        </div>

                        <!-- converter selector: standard vs burn -->
                        <div
                            class="grid grid-cols-2 gap-1 rounded-xl border border-border bg-background/50 p-1"
                        >
                            <button
                                v-for="key in swapModes"
                                :key="key"
                                type="button"
                                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                                :class="
                                    mode === key
                                        ? 'bg-card text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="mode = key"
                            >
                                {{ SWAP_CONTRACTS[key].label }}
                            </button>
                        </div>
                        <p
                            v-if="activeSwap.burns"
                            class="rounded-lg bg-orange-500/10 px-3 py-2 text-center text-[11px] text-orange-600 dark:text-orange-400"
                        >
                            Burn mode: the CYBER.sol you pay in is sent to
                            0x…dEaD and permanently removed from supply.
                        </p>

                        <!-- You pay -->
                        <div
                            class="space-y-2 rounded-2xl border border-border bg-background/50 p-4"
                        >
                            <div
                                class="flex items-center justify-between text-xs text-muted-foreground"
                            >
                                <span>You pay</span>
                                <span v-if="hasWallet" class="font-mono">
                                    Balance: {{ fmt(myCyberSol, 4) }}
                                </span>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="swapAmount"
                                    type="number"
                                    min="0"
                                    step="1"
                                    inputmode="decimal"
                                    placeholder="0"
                                    class="w-full min-w-0 bg-transparent text-2xl font-semibold tabular-nums outline-none placeholder:text-muted-foreground/50"
                                />
                                <div
                                    class="flex shrink-0 items-center gap-2 rounded-full border border-border bg-card py-1 pl-1 pr-3"
                                >
                                    <span
                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-teal-300 text-xs font-bold text-white"
                                        >◎</span
                                    >
                                    <div class="text-left leading-tight">
                                        <div class="text-sm font-semibold">
                                            CYBER.sol
                                        </div>
                                        <div
                                            class="text-[10px] text-muted-foreground"
                                        >
                                            bridged
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div
                                v-if="hasWallet && myCyberSol > 0n"
                                class="flex gap-1.5"
                            >
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-2 py-0.5 text-[11px] font-medium text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                                    @click="setSwapFraction(1n, 4n)"
                                >
                                    25%
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-2 py-0.5 text-[11px] font-medium text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                                    @click="setSwapFraction(1n, 2n)"
                                >
                                    50%
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-border px-2 py-0.5 text-[11px] font-medium text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                                    @click="setSwapFraction(1n, 1n)"
                                >
                                    MAX
                                </button>
                            </div>
                        </div>

                        <!-- direction divider -->
                        <div class="flex justify-center">
                            <div
                                class="-my-5 flex h-9 w-9 items-center justify-center rounded-xl border border-border bg-card shadow-sm"
                            >
                                <ArrowDown
                                    class="h-4 w-4 text-muted-foreground"
                                />
                            </div>
                        </div>

                        <!-- You receive -->
                        <div
                            class="space-y-2 rounded-2xl border border-border bg-background/50 p-4"
                        >
                            <div class="text-xs text-muted-foreground">
                                You receive
                            </div>
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-full min-w-0 truncate text-2xl font-semibold tabular-nums"
                                    :class="
                                        swapAmountOut > 0n
                                            ? 'text-foreground'
                                            : 'text-muted-foreground/50'
                                    "
                                >
                                    {{
                                        swapAmountOut > 0n
                                            ? fmt(swapAmountOut)
                                            : '0'
                                    }}
                                </div>
                                <div
                                    class="flex shrink-0 items-center gap-2 rounded-full border border-emerald-500/40 bg-emerald-500/5 py-1 pl-1 pr-3"
                                >
                                    <span
                                        class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-teal-300 to-teal-400 text-xs font-bold text-white"
                                        >C</span
                                    >
                                    <div class="text-left leading-tight">
                                        <div class="text-sm font-semibold">
                                            CYBER
                                        </div>
                                        <div
                                            class="text-[10px] text-emerald-600 dark:text-emerald-400"
                                        >
                                            native
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- CTA -->
                        <Button
                            v-if="!hasWallet"
                            class="h-12 w-full rounded-xl text-base"
                            :disabled="connecting"
                            @click="connectWallet"
                        >
                            <Loader2
                                v-if="connecting"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            <Wallet v-else class="mr-2 h-4 w-4" />
                            Connect wallet
                        </Button>
                        <Button
                            v-else
                            class="h-12 w-full rounded-xl text-base"
                            :disabled="swapBusy || !swapReady"
                            @click="doSwap"
                        >
                            <Loader2
                                v-if="swapBusy"
                                class="mr-2 h-4 w-4 animate-spin"
                            />
                            <span v-if="swapBusy">Swapping…</span>
                            <span v-else-if="swapAmountIn <= 0n"
                                >Enter an amount</span
                            >
                            <span v-else-if="swapAmountIn > myCyberSol"
                                >Insufficient CYBER.sol</span
                            >
                            <span v-else-if="swapAmountOut > liquidity"
                                >Not enough liquidity</span
                            >
                            <span v-else>Swap to native CYBER</span>
                        </Button>

                        <p
                            v-if="swapAmountIn > 0n"
                            class="flex flex-wrap items-center justify-center gap-1 text-center text-xs text-muted-foreground"
                        >
                            {{ fmt(swapAmountIn) }} CYBER.sol
                            <ArrowRight class="h-3 w-3" />
                            <span class="font-medium text-foreground"
                                >{{ fmt(swapAmountOut) }} CYBER</span
                            >
                            · rounded to multiples of {{ RATE }} wei
                        </p>

                        <!-- status / error -->
                        <p
                            v-if="status"
                            class="rounded-lg bg-muted px-3 py-2 text-center text-xs text-muted-foreground"
                        >
                            {{ status }}
                        </p>
                        <p
                            v-if="error"
                            class="rounded-lg bg-destructive/10 px-3 py-2 text-center text-xs text-destructive"
                        >
                            {{ error }}
                        </p>
                    </div>
                </div>

                <!-- still on Solana -->
                <div
                    class="flex items-center justify-between gap-3 rounded-2xl border border-border bg-card/50 px-4 py-3 text-sm"
                >
                    <span class="flex items-center gap-2 text-muted-foreground">
                        <span
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-teal-300 text-[10px] font-bold text-white"
                            >◎</span
                        >
                        CYBER still on Solana?
                    </span>
                    <a
                        href="https://bridge.cyberia.church"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                    >
                        Bridge it first
                        <ArrowRight class="h-3.5 w-3.5" />
                    </a>
                </div>

                <p
                    v-if="isOwner"
                    class="flex items-center justify-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400"
                >
                    <ShieldCheck class="h-3.5 w-3.5" />
                    Connected as contract owner
                </p>
            </section>

            <!-- HOW IT WORKS -->
            <section class="space-y-6">
                <div class="space-y-1 text-center">
                    <h2 class="text-2xl font-semibold">
                        Two steps to native CYBER
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Your CYBER lives on Solana as an SPL token. Here's how it
                        comes home.
                    </p>
                </div>
                <ol class="grid gap-4 sm:grid-cols-3">
                    <li class="rounded-2xl border border-border bg-card p-5">
                        <div
                            class="mb-3 flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-cyan-400 to-fuchsia-500 text-sm font-bold text-white"
                        >
                            1
                        </div>
                        <h3 class="font-semibold">Bridge from Solana</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Lock your SPL CYBER on Solana and mint CYBER.sol on
                            the Cyberia chain through the official bridge.
                        </p>
                    </li>
                    <li class="rounded-2xl border border-border bg-card p-5">
                        <div
                            class="mb-3 flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-fuchsia-500 to-teal-300 text-sm font-bold text-white"
                        >
                            2
                        </div>
                        <h3 class="font-semibold">Redeem here</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Swap CYBER.sol for native CYBER at a fixed
                            1000&nbsp;:&nbsp;1 rate — no order book, no slippage,
                            no surprises.
                        </p>
                    </li>
                    <li class="rounded-2xl border border-border bg-card p-5">
                        <div
                            class="mb-3 flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-teal-300 to-teal-400 text-sm font-bold text-white"
                        >
                            3
                        </div>
                        <h3 class="font-semibold">Use it everywhere</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Native CYBER is the gas and money of the whole
                            Cyberia chain — spend it, stake it, govern with it.
                        </p>
                    </li>
                </ol>
            </section>

            <!-- BENEFITS -->
            <section class="space-y-6">
                <div class="space-y-1 text-center">
                    <h2 class="text-2xl font-semibold">
                        What native CYBER unlocks
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        CYBER.sol just sits in your wallet. Native CYBER does
                        everything.
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl border border-border bg-card p-5">
                        <Fuel class="h-6 w-6 text-violet-500" />
                        <h3 class="mt-3 font-semibold">Pay for gas</h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Every transaction on Cyberia is paid in native
                            CYBER.
                        </p>
                    </div>
                    <a
                        href="/dao"
                        class="group rounded-2xl border border-border bg-card p-5 transition hover:border-foreground/30"
                    >
                        <Vote class="h-6 w-6 text-fuchsia-500" />
                        <h3
                            class="mt-3 flex items-center gap-1 font-semibold"
                        >
                            Govern the DAO
                            <ArrowRight
                                class="h-3.5 w-3.5 opacity-0 transition group-hover:opacity-100"
                            />
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Vote on proposals and steer the protocol's future.
                        </p>
                    </a>
                    <a
                        href="/lending"
                        class="group rounded-2xl border border-border bg-card p-5 transition hover:border-foreground/30"
                    >
                        <Landmark class="h-6 w-6 text-sky-500" />
                        <h3
                            class="mt-3 flex items-center gap-1 font-semibold"
                        >
                            Lend &amp; borrow
                            <ArrowRight
                                class="h-3.5 w-3.5 opacity-0 transition group-hover:opacity-100"
                            />
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Supply CYBER to earn yield or borrow against it.
                        </p>
                    </a>
                    <a
                        href="https://swap.cyberia.church/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="group rounded-2xl border border-border bg-card p-5 transition hover:border-foreground/30"
                    >
                        <Sparkles class="h-6 w-6 text-emerald-500" />
                        <h3
                            class="mt-3 flex items-center gap-1 font-semibold"
                        >
                            Trade on the DEX
                            <ExternalLink
                                class="h-3.5 w-3.5 opacity-0 transition group-hover:opacity-100"
                            />
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Swap CYBER against the whole Cyberia token economy.
                        </p>
                    </a>
                </div>
            </section>

            <!-- STATS -->
            <section
                class="grid gap-4 rounded-2xl border border-border bg-card/50 p-5 sm:grid-cols-4"
            >
                <div>
                    <p class="text-xs text-muted-foreground">CYBER liquidity</p>
                    <p class="font-mono text-lg">{{ fmt(liquidity) }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">
                        {{
                            activeSwap.burns
                                ? 'CYBER.sol burned'
                                : 'CYBER.sol redeemed'
                        }}
                    </p>
                    <p class="font-mono text-lg">{{ fmt(collected) }}</p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Your CYBER.sol</p>
                    <p class="font-mono text-lg">
                        {{ hasWallet ? fmt(myCyberSol) : '—' }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">Your CYBER</p>
                    <p class="font-mono text-lg">
                        {{ hasWallet ? fmt(myNative) : '—' }}
                    </p>
                </div>
                <div class="sm:col-span-4">
                    <a
                        :href="explorerUrl(swapAddress)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-mono text-xs text-muted-foreground hover:text-foreground"
                    >
                        Contract {{ shortAddr(swapAddress) }}
                        <ExternalLink class="h-3 w-3" />
                    </a>
                </div>
            </section>

            <!-- FUND LIQUIDITY -->
            <section class="space-y-3 rounded-2xl border border-border p-5">
                <div>
                    <h2 class="font-semibold">Fund liquidity</h2>
                    <p class="text-xs text-muted-foreground">
                        Help the redeemer pay out by sending native CYBER to the
                        contract. Anyone can top it up.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Input
                        v-model="fundAmount"
                        type="number"
                        min="0"
                        step="0.001"
                        placeholder="Amount in CYBER"
                        class="max-w-xs font-mono"
                    />
                    <Button :disabled="fundBusy" @click="fundLiquidity">
                        <Loader2
                            v-if="fundBusy"
                            class="mr-1 h-4 w-4 animate-spin"
                        />
                        Fund
                    </Button>
                </div>
            </section>

            <!-- OWNER CONTROLS -->
            <section
                v-if="isOwner"
                class="space-y-4 rounded-2xl border border-amber-500/40 bg-amber-500/5 p-5"
            >
                <div class="flex items-center gap-2">
                    <ShieldCheck class="h-4 w-4 text-amber-600" />
                    <h2 class="font-semibold">Owner withdrawals</h2>
                </div>

                <div class="space-y-2">
                    <p class="text-xs text-muted-foreground">
                        Withdraw native CYBER liquidity (blank recipient = your
                        wallet).
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            v-model="wNativeAmount"
                            type="number"
                            min="0"
                            step="0.001"
                            placeholder="Amount CYBER"
                            class="max-w-[12rem] font-mono"
                        />
                        <Input
                            v-model="wNativeTo"
                            placeholder="Recipient (optional)"
                            class="max-w-xs font-mono"
                        />
                        <Button
                            variant="outline"
                            :disabled="wNativeBusy"
                            @click="withdrawNative"
                        >
                            <Loader2
                                v-if="wNativeBusy"
                                class="mr-1 h-4 w-4 animate-spin"
                            />
                            Withdraw CYBER
                        </Button>
                    </div>
                </div>

                <!-- burn converter holds no tokens, so withdrawTokens is hidden -->
                <div v-if="!activeSwap.burns" class="space-y-2">
                    <p class="text-xs text-muted-foreground">
                        Withdraw collected CYBER.sol (blank recipient = your
                        wallet).
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Input
                            v-model="wTokenAmount"
                            type="number"
                            min="0"
                            step="1"
                            placeholder="Amount CYBER.sol"
                            class="max-w-[12rem] font-mono"
                        />
                        <Input
                            v-model="wTokenTo"
                            placeholder="Recipient (optional)"
                            class="max-w-xs font-mono"
                        />
                        <Button
                            variant="outline"
                            :disabled="wTokenBusy"
                            @click="withdrawTokens"
                        >
                            <Loader2
                                v-if="wTokenBusy"
                                class="mr-1 h-4 w-4 animate-spin"
                            />
                            Withdraw CYBER.sol
                        </Button>
                    </div>
                </div>
            </section>
        </div>
    </main>
</template>
