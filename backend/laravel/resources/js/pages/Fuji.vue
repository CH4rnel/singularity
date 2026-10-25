<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Check, Copy, Droplets, Fuel, Wallet } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import PageHero from '@/components/web3/PageHero.vue';
import { useLocale } from '@/composables/useLocale';
import { ensureEvmChain } from '@/lib/evmChains';
import type { EvmChain } from '@/lib/evmChains';
import {
    getEvmProvider,
    getMetaMaskProvider,
    requestEvmWalletProviders,
} from '@/lib/evmProvider';
import { fujiMessages } from '@/lib/fujiMessages';
import { rpc as fujiRpc } from '@/routes/api/fuji';
import {
    claim as faucetClaim,
    status as faucetStatus,
} from '@/routes/api/fuji/faucet';
import type { EthereumProvider } from '@/types/global';

type Network = {
    chainId: number;
    name: string;
    rpcUrl: string;
    currency: { name: string; symbol: string; decimals: number };
    explorerTx: string | null;
};

type FaucetStatus = {
    enabled: boolean;
    chainId: number;
    gasPrice: string | null;
    feeRecipient: string | null;
    faucet: string | null;
    drip: string;
    cooldownHours: number;
    tank: string | null;
};

type ClaimResult =
    | { ok: true; txHash: string; confirmed: boolean }
    | { ok: false; reason: string; wait?: number };

const props = defineProps<{ network: Network; treasury: string }>();

const { t } = useLocale(fujiMessages);

const chain = computed<EvmChain>(() => ({
    chainId: props.network.chainId,
    name: props.network.name,
    nativeCurrency: props.network.currency,
    rpcUrls: [props.network.rpcUrl],
    status: 'live',
}));

/* ------------------------------------------------------------- amounts --- */

const WEI = 10n ** 18n;

/** Whole CYBER with up to two decimals, grouped for the reader's locale. */
function cyber(wei: string | bigint | null): string {
    if (wei === null) {
        return '—';
    }

    const value = BigInt(wei);
    const whole = value / WEI;
    const cents = Number(((value % WEI) * 100n) / WEI);

    return (Number(whole) + cents / 100).toLocaleString(undefined, {
        maximumFractionDigits: 2,
    });
}

const short = (address: string): string =>
    `${address.slice(0, 6)}…${address.slice(-4)}`;

/* -------------------------------------------------------------- wallet --- */

const provider = ref<EthereumProvider | null>(null);
const isMetaMask = ref(false);
const account = ref<string | null>(null);
const walletChainId = ref<number | null>(null);
const balance = ref<string | null>(null);
const adding = ref(false);
const walletError = ref<string | null>(null);

const onFuji = computed(() => walletChainId.value === props.network.chainId);

async function readBalance(address: string): Promise<string | null> {
    try {
        const response = await fetch(fujiRpc.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                jsonrpc: '2.0',
                id: 1,
                method: 'eth_getBalance',
                params: [address, 'latest'],
            }),
        });
        const body = (await response.json()) as { result?: string };

        return body.result ? BigInt(body.result).toString() : null;
    } catch {
        return null;
    }
}

async function refreshWallet(): Promise<void> {
    const eth = provider.value;

    if (!eth) {
        return;
    }

    try {
        const accounts = (await eth.request({
            method: 'eth_accounts',
        })) as string[];
        account.value = accounts[0] ?? null;
        walletChainId.value = parseInt(
            (await eth.request({ method: 'eth_chainId' })) as string,
            16,
        );
    } catch {
        account.value = null;
    }

    if (account.value) {
        if (!addressInput.value) {
            addressInput.value = account.value;
        }

        balance.value = await readBalance(account.value);
    }
}

/** Connect, then switch to Cyberia Fuji — adding it first if the wallet does not know it. */
async function addNetwork(): Promise<void> {
    const eth = provider.value;

    if (!eth) {
        return;
    }

    adding.value = true;
    walletError.value = null;

    try {
        await eth.request({ method: 'eth_requestAccounts' });
        await ensureEvmChain(eth, chain.value);
    } catch (error) {
        const code = (error as { code?: number }).code;
        const message = (error as { message?: string }).message ?? '';

        walletError.value =
            code === 4001
                ? t('rejected')
                : t('walletError', { message: message || String(error) });
    } finally {
        adding.value = false;
        await refreshWallet();
    }
}

const onAccountsChanged = (): void => {
    void refreshWallet();
};

/* -------------------------------------------------------------- faucet --- */

const status = ref<FaucetStatus | null>(null);
const addressInput = ref('');
const claiming = ref(false);
const result = ref<ClaimResult | null>(null);

const drip = computed(() => cyber(status.value?.drip ?? null));

const transferCost = computed(() =>
    status.value?.gasPrice
        ? cyber(BigInt(status.value.gasPrice) * 21000n)
        : null,
);

const feesToTreasury = computed(
    () =>
        status.value?.feeRecipient?.toLowerCase() ===
        props.treasury.toLowerCase(),
);

async function loadStatus(): Promise<void> {
    try {
        const response = await fetch(faucetStatus.url(), {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            status.value = (await response.json()) as FaucetStatus;
        }
    } catch {
        // The page still adds the network; the faucet card says it is closed.
    }
}

function waitText(seconds: number): string {
    const minutes = Math.ceil(seconds / 60);

    return t('wait', {
        hours: Math.floor(minutes / 60),
        minutes: minutes % 60,
    });
}

function reasonText(outcome: { reason: string; wait?: number }): string {
    if (outcome.reason === 'coolingDown') {
        return t('reason_coolingDown', { wait: waitText(outcome.wait ?? 0) });
    }

    return t(`reason_${outcome.reason}`, { drip: drip.value });
}

async function claim(): Promise<void> {
    const address = addressInput.value.trim();

    if (!/^0x[a-fA-F0-9]{40}$/.test(address)) {
        result.value = { ok: false, reason: 'invalid' };

        return;
    }

    claiming.value = true;
    result.value = null;

    try {
        // Asked first so a refusal can say how long the cooldown has left.
        const check = await fetch(faucetStatus.url({ query: { address } }), {
            headers: { Accept: 'application/json' },
        });
        const checked = (await check.json().catch(() => ({}))) as {
            address?: {
                ok: boolean;
                reason: string;
                cooldownRemaining: number;
            };
        };

        if (checked.address && !checked.address.ok) {
            result.value = {
                ok: false,
                reason: checked.address.reason,
                wait: checked.address.cooldownRemaining,
            };

            return;
        }

        const response = await fetch(faucetClaim.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ address }),
        });
        const body = (await response.json().catch(() => ({}))) as {
            status?: string;
            txHash?: string;
            confirmed?: boolean;
            reason?: string;
        };

        if (response.ok && body.status === 'sent' && body.txHash) {
            result.value = {
                ok: true,
                txHash: body.txHash,
                confirmed: body.confirmed ?? false,
            };
        } else {
            result.value = {
                ok: false,
                reason:
                    response.status === 429
                        ? 'tooMany'
                        : (body.reason ?? 'unreadable'),
            };
        }
    } catch {
        result.value = { ok: false, reason: 'unreadable' };
    } finally {
        claiming.value = false;
        await loadStatus();

        if (account.value) {
            balance.value = await readBalance(account.value);
        }
    }
}

/* ---------------------------------------------------------------- copy --- */

const copiedKey = ref<string | null>(null);

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copiedKey.value = key;
        window.setTimeout(() => {
            if (copiedKey.value === key) {
                copiedKey.value = null;
            }
        }, 1500);
    } catch {
        // A browser that refuses the clipboard leaves the value on the page
        // to be selected by hand.
    }
}

const parameters = computed(() => [
    { key: 'networkName', value: props.network.name },
    { key: 'rpcUrl', value: props.network.rpcUrl },
    { key: 'chainId', value: String(props.network.chainId) },
    { key: 'currency', value: props.network.currency.symbol },
]);

/* ----------------------------------------------------------- lifecycle --- */

onMounted(() => {
    void loadStatus();
    requestEvmWalletProviders();

    // Wallets announce themselves on the event loop; give them one turn.
    window.setTimeout(() => {
        const metaMask = getMetaMaskProvider();
        provider.value = metaMask ?? getEvmProvider();
        isMetaMask.value = metaMask !== null;

        provider.value?.on('accountsChanged', onAccountsChanged);
        provider.value?.on('chainChanged', onAccountsChanged);
        void refreshWallet();
    }, 150);
});

onBeforeUnmount(() => {
    const eth = provider.value as
        | (EthereumProvider & {
              removeListener?: (
                  event: string,
                  listener: (...args: unknown[]) => void,
              ) => void;
          })
        | null;

    eth?.removeListener?.('accountsChanged', onAccountsChanged);
    eth?.removeListener?.('chainChanged', onAccountsChanged);
});
</script>

<template>
    <Head :title="t('title')" />

    <div class="mx-auto max-w-3xl space-y-6 p-6">
        <PageHero
            :eyebrow="t('eyebrow')"
            :title="t('title')"
            :description="t('intro')"
        />

        <!-- 1 · the network -->
        <section class="rounded-xl border bg-card p-5 shadow-xs">
            <div class="flex items-center gap-3">
                <span
                    class="flex size-7 items-center justify-center rounded-full border border-brand-cyan/50 font-mono text-xs text-brand-cyan"
                >
                    1
                </span>
                <h2 class="text-lg font-semibold">{{ t('stepNetwork') }}</h2>
            </div>
            <p class="mt-2 text-sm leading-6 text-muted-foreground">
                {{ t('stepNetworkBody') }}
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <template v-if="provider">
                    <Button
                        v-if="!account || !onFuji"
                        size="lg"
                        :disabled="adding"
                        data-test="add-network"
                        @click="addNetwork"
                    >
                        <Spinner v-if="adding" class="mr-1 size-4" />
                        <Wallet v-else class="mr-1 size-4" />
                        {{
                            adding
                                ? t('adding')
                                : account
                                  ? t('switchNow')
                                  : isMetaMask
                                    ? t('addToMetaMask')
                                    : t('addToWallet')
                        }}
                    </Button>

                    <div
                        v-if="account"
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-sm"
                        data-test="wallet-state"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="onFuji ? 'bg-brand-cyan' : 'bg-amber-500'"
                        />
                        <span>{{ t('connected') }}</span>
                        <span class="font-mono text-xs">{{
                            short(account)
                        }}</span>
                        <span class="text-muted-foreground">
                            {{ onFuji ? t('onFuji') : t('otherChain') }}
                        </span>
                        <span v-if="balance !== null" class="font-mono text-xs">
                            · {{ t('balance') }} {{ cyber(balance) }}
                            {{ network.currency.symbol }}
                        </span>
                    </div>
                </template>

                <template v-else>
                    <Button as-child size="lg">
                        <a
                            href="https://metamask.io/download/"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Wallet class="mr-1 size-4" />
                            {{ t('installMetaMask') }}
                        </a>
                    </Button>
                    <p class="text-sm text-muted-foreground">
                        {{ t('noWallet') }}
                    </p>
                </template>
            </div>

            <p
                v-if="walletError"
                class="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-muted-foreground"
            >
                {{ walletError }}
            </p>

            <dl class="mt-5 divide-y rounded-lg border text-sm">
                <div
                    v-for="parameter in parameters"
                    :key="parameter.key"
                    class="flex items-center justify-between gap-3 px-3 py-2"
                >
                    <dt class="shrink-0 text-muted-foreground">
                        {{ t(parameter.key) }}
                    </dt>
                    <dd class="flex min-w-0 items-center gap-2">
                        <span class="text-right font-mono text-xs break-all">
                            {{ parameter.value }}
                        </span>
                        <button
                            type="button"
                            class="shrink-0 text-muted-foreground hover:text-foreground"
                            :aria-label="t('copy')"
                            :title="
                                copiedKey === parameter.key
                                    ? t('copied')
                                    : t('copy')
                            "
                            @click="copy(parameter.key, parameter.value)"
                        >
                            <Check
                                v-if="copiedKey === parameter.key"
                                class="size-4 text-brand-cyan"
                            />
                            <Copy v-else class="size-4" />
                        </button>
                    </dd>
                </div>
                <div class="flex items-center justify-between gap-3 px-3 py-2">
                    <dt class="shrink-0 text-muted-foreground">
                        {{ t('explorer') }}
                    </dt>
                    <dd class="text-xs text-muted-foreground">
                        {{ t('explorerNone') }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- 2 · the faucet -->
        <section class="rounded-xl border bg-card p-5 shadow-xs">
            <div class="flex items-center gap-3">
                <span
                    class="flex size-7 items-center justify-center rounded-full border border-brand-cyan/50 font-mono text-xs text-brand-cyan"
                >
                    2
                </span>
                <h2 class="text-lg font-semibold">{{ t('stepFaucet') }}</h2>
            </div>

            <template v-if="status?.enabled">
                <p class="mt-2 text-sm leading-6 text-muted-foreground">
                    {{
                        t('stepFaucetBody', {
                            drip,
                            hours: status.cooldownHours,
                        })
                    }}
                </p>

                <form
                    class="mt-4 flex flex-col gap-3 sm:flex-row"
                    @submit.prevent="claim"
                >
                    <label class="sr-only" for="faucet-address">
                        {{ t('addressLabel') }}
                    </label>
                    <Input
                        id="faucet-address"
                        v-model="addressInput"
                        :placeholder="t('addressPlaceholder')"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono text-sm"
                        data-test="faucet-address"
                    />
                    <Button
                        type="submit"
                        size="lg"
                        :disabled="claiming"
                        class="shrink-0"
                        data-test="faucet-send"
                    >
                        <Spinner v-if="claiming" class="mr-1 size-4" />
                        <Droplets v-else class="mr-1 size-4" />
                        {{ claiming ? t('sending') : t('send', { drip }) }}
                    </Button>
                </form>

                <button
                    v-if="
                        account &&
                        addressInput.toLowerCase() !== account.toLowerCase()
                    "
                    type="button"
                    class="mt-2 text-xs text-brand-cyan hover:underline"
                    @click="addressInput = account"
                >
                    {{ t('useConnected') }}
                </button>

                <div
                    v-if="result"
                    class="mt-4 rounded-lg border p-3 text-sm"
                    :class="
                        result.ok
                            ? 'border-brand-cyan/40 bg-brand-cyan/5'
                            : 'border-amber-500/40 bg-amber-500/5 text-muted-foreground'
                    "
                    data-test="faucet-result"
                >
                    <template v-if="result.ok">
                        <p class="font-medium">
                            {{
                                result.confirmed
                                    ? t('sent', { drip })
                                    : t('sentPending', { drip })
                            }}
                        </p>
                        <p class="mt-1 flex min-w-0 items-center gap-2">
                            <span class="text-muted-foreground">
                                {{ t('transaction') }}
                            </span>
                            <a
                                v-if="network.explorerTx"
                                :href="
                                    network.explorerTx.replace(
                                        '{hash}',
                                        result.txHash,
                                    )
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="font-mono text-xs break-all text-brand-cyan hover:underline"
                            >
                                {{ result.txHash }}
                            </a>
                            <span v-else class="font-mono text-xs break-all">
                                {{ result.txHash }}
                            </span>
                            <button
                                type="button"
                                class="shrink-0 text-muted-foreground hover:text-foreground"
                                :aria-label="t('copy')"
                                @click="copy('tx', result.txHash)"
                            >
                                <Check
                                    v-if="copiedKey === 'tx'"
                                    class="size-4 text-brand-cyan"
                                />
                                <Copy v-else class="size-4" />
                            </button>
                        </p>
                    </template>
                    <p v-else>{{ reasonText(result) }}</p>
                </div>

                <p
                    v-if="status.faucet"
                    class="mt-4 text-xs text-muted-foreground"
                >
                    {{
                        t('tank', {
                            address: short(status.faucet),
                            amount: cyber(status.tank),
                        })
                    }}
                </p>
            </template>

            <p v-else class="mt-2 text-sm text-muted-foreground">
                {{ t('faucetOff') }}
            </p>
        </section>

        <!-- fees -->
        <section class="rounded-xl border bg-card p-5 text-sm">
            <div class="flex items-start gap-3">
                <Fuel class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div
                    class="min-w-0 space-y-2 leading-6 wrap-anywhere text-muted-foreground"
                >
                    <p class="font-medium text-foreground">{{ t('fees') }}</p>
                    <p v-if="transferCost" data-test="fee-line">
                        {{
                            t('feesBody', {
                                cost: transferCost,
                            })
                        }}
                    </p>
                    <p v-else>{{ t('feesUnknown') }}</p>
                    <p v-if="feesToTreasury && status?.feeRecipient">
                        {{
                            t('feesToTreasury', {
                                address: status.feeRecipient,
                            })
                        }}
                    </p>
                    <p v-else-if="status && !status.feeRecipient">
                        {{ t('feesToProducer') }}
                    </p>
                </div>
            </div>
        </section>
    </div>
</template>
