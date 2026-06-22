<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BrowserProvider,
    Contract,
    JsonRpcProvider,
    formatEther,
    parseEther,
} from 'ethers';
import { Loader2 } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import Header from '@/components/Header.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWallet } from '@/composables/useWallet';
import { getMetaMaskProvider } from '@/lib/evmProvider';

const CYBERIA_CHAIN_ID = 49406;
const CYBERIA_CHAIN_ID_HEX = '0xc11e';
const CYBERIA_RPC = '/api/rpc/cyberia';
const CYBERIA_PUBLIC_RPC = 'https://rpc.cyberia.church';

// CyberSolSwap: fixed-rate redeemer converting bridged CYBER.sol -> native CYBER
// at 1000 : 1. Payouts come from the contract's own native balance, so it must
// be funded with CYBER (the "fund liquidity" panel below).
const SWAP_CONTRACT = '0x69b1614B088F5670E49bcC6fE33F28F2544F7415';
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
    'function withdrawTokens(address to, uint256 amount)',
];

const ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address,address) view returns (uint256)',
    'function approve(address,uint256) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
];

const wallet = useWallet();

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
        const swap = new Contract(SWAP_CONTRACT, SWAP_ABI, readProvider);
        const token = new Contract(CYBER_SOL_ADDRESS, ERC20_ABI, readProvider);

        const [bal, coll, own] = await Promise.all([
            readProvider.getBalance(SWAP_CONTRACT),
            token.balanceOf(SWAP_CONTRACT) as Promise<bigint>,
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
                token.allowance(me, SWAP_CONTRACT) as Promise<bigint>,
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
            to: SWAP_CONTRACT,
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
            const atx = await token.approve(SWAP_CONTRACT, amountIn);
            await atx.wait();
        }

        status.value = 'Confirm the swap in your wallet…';
        const swap = new Contract(SWAP_CONTRACT, SWAP_ABI, signer);
        const tx = await swap.swap(amountIn);
        status.value = 'Waiting for block…';
        await tx.wait();
        status.value = `Swapped ${fmt(amountIn)} CYBER.sol → ${fmt(amountOut)} CYBER.`;
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
        const swap = new Contract(SWAP_CONTRACT, SWAP_ABI, signer);
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
        const swap = new Contract(SWAP_CONTRACT, SWAP_ABI, signer);
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

onMounted(loadState);
// Reload balances once a wallet connects / switches account.
watch(() => wallet.address.value, loadState);
</script>

<template>
    <Head title="CYBER.sol Swap" />

    <Header />

    <div class="mx-auto max-w-3xl space-y-6 p-6">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold">CYBER.sol → CYBER swap</h1>
            <p class="text-sm text-muted-foreground">
                Fixed-rate redeemer at
                <span class="font-mono">1000 CYBER.sol : 1 CYBER</span>. Payouts
                come from the contract's own CYBER balance, so it must hold
                liquidity before swaps succeed.
            </p>
            <a
                :href="explorerUrl(SWAP_CONTRACT)"
                target="_blank"
                rel="noopener noreferrer"
                class="font-mono text-xs text-primary hover:underline"
            >
                {{ SWAP_CONTRACT }}
            </a>
        </header>

        <!-- Contract state -->
        <section
            class="grid grid-cols-2 gap-4 rounded-lg border border-border p-4"
        >
            <div>
                <p class="text-xs text-muted-foreground">CYBER liquidity</p>
                <p class="font-mono text-lg">{{ fmt(liquidity) }} CYBER</p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">CYBER.sol collected</p>
                <p class="font-mono text-lg">{{ fmt(collected) }}</p>
            </div>
            <div v-if="wallet.address.value">
                <p class="text-xs text-muted-foreground">Your CYBER.sol</p>
                <p class="font-mono">{{ fmt(myCyberSol) }}</p>
            </div>
            <div v-if="wallet.address.value">
                <p class="text-xs text-muted-foreground">Your CYBER</p>
                <p class="font-mono">{{ fmt(myNative) }}</p>
            </div>
            <div class="col-span-2 flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="loading"
                    @click="loadState"
                >
                    <Loader2 v-if="loading" class="mr-1 h-3 w-3 animate-spin" />
                    Refresh
                </Button>
                <span v-if="isOwner" class="text-xs text-green-600"
                    >You are the contract owner</span
                >
            </div>
        </section>

        <!-- Fund liquidity -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <div>
                <h2 class="font-semibold">Fund liquidity</h2>
                <p class="text-xs text-muted-foreground">
                    Send native CYBER to the contract so it can pay out swaps.
                </p>
            </div>
            <div class="flex items-center gap-2">
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

        <!-- Swap -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <div>
                <h2 class="font-semibold">Swap CYBER.sol → CYBER</h2>
                <p class="text-xs text-muted-foreground">
                    Amount is rounded down to a multiple of {{ RATE }} wei.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Input
                    v-model="swapAmount"
                    type="number"
                    min="0"
                    step="1"
                    placeholder="Amount in CYBER.sol"
                    class="max-w-xs font-mono"
                />
                <Button :disabled="swapBusy" @click="doSwap">
                    <Loader2
                        v-if="swapBusy"
                        class="mr-1 h-4 w-4 animate-spin"
                    />
                    Swap
                </Button>
            </div>
            <p v-if="swapAmountIn > 0n" class="text-sm text-muted-foreground">
                You receive
                <span class="font-mono text-foreground"
                    >{{ fmt(swapAmountOut) }} CYBER</span
                >
                for
                <span class="font-mono text-foreground"
                    >{{ fmt(swapAmountIn) }} CYBER.sol</span
                >
            </p>
        </section>

        <!-- Owner controls -->
        <section
            v-if="isOwner"
            class="space-y-4 rounded-lg border border-amber-500/40 p-4"
        >
            <h2 class="font-semibold">Owner controls</h2>

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

            <div class="space-y-2">
                <p class="text-xs text-muted-foreground">
                    Withdraw collected CYBER.sol (blank recipient = your wallet).
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

        <p v-if="status" class="text-sm text-muted-foreground">{{ status }}</p>
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>
</template>
