<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Copy,
    ExternalLink,
    Loader2,
    Network,
    ShieldCheck,
    Wallet,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import EmptyState from '@/components/web3/EmptyState.vue';
import PageHero from '@/components/web3/PageHero.vue';
import PageShell from '@/components/web3/PageShell.vue';
import { useWallet } from '@/composables/useWallet';
import { ROBINHOOD_GROWTH } from '@/lib/growthCampaigns';
import { attributedUrl, track } from '@/lib/track';
import { bridge, farm, liquidity, swap } from '@/routes';

type BridgeRoute = {
    direction: string;
    source: string;
    destination: string;
    sourceLabel: string;
    destinationLabel: string;
    operational: boolean;
    unavailableReason: string | null;
    tokens: string[];
};

type FaqItem = {
    question: string;
    answer: string;
};

const props = defineProps<{
    bridgeRoutes: BridgeRoute[];
    faq: FaqItem[];
    seo: {
        title: string;
        description: string;
        canonical: string;
        image: string;
    };
}>();

const wallet = useWallet();
const switchingNetwork = ref(false);
const copied = ref<string | null>(null);

const liveInbound = computed(() =>
    props.bridgeRoutes.find(
        (route) =>
            route.source === 'robinhood' &&
            route.destination === 'cyberia' &&
            route.operational,
    ),
);
const outbound = computed(() =>
    props.bridgeRoutes.find(
        (route) =>
            route.source === 'cyberia' && route.destination === 'robinhood',
    ),
);

const contracts = [
    {
        label: 'Ritual router',
        address: ROBINHOOD_GROWTH.dex.router,
        url: `${ROBINHOOD_GROWTH.dex.explorer}/address/${ROBINHOOD_GROWTH.dex.router}`,
    },
    {
        label: 'Ritual factory',
        address: ROBINHOOD_GROWTH.dex.factory,
        url: `${ROBINHOOD_GROWTH.dex.explorer}/address/${ROBINHOOD_GROWTH.dex.factory}`,
    },
    {
        label: 'Funded farm',
        address: ROBINHOOD_GROWTH.farm.masterchef,
        url: `${ROBINHOOD_GROWTH.farm.explorer}/address/${ROBINHOOD_GROWTH.farm.masterchef}`,
    },
];

const faqJson = JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: props.faq.map((item) => ({
        '@type': 'Question',
        name: item.question,
        acceptedAnswer: {
            '@type': 'Answer',
            text: item.answer,
        },
    })),
});

const connectWallet = async (): Promise<void> => {
    await wallet.connect();
};

const switchToRobinhood = async (): Promise<void> => {
    switchingNetwork.value = true;

    try {
        await wallet.switchChain(ROBINHOOD_GROWTH.chain);
    } finally {
        switchingNetwork.value = false;
    }
};

const copyAddress = async (address: string): Promise<void> => {
    try {
        await navigator.clipboard.writeText(address);
        copied.value = address;
        window.setTimeout(() => {
            if (copied.value === address) {
                copied.value = null;
            }
        }, 1600);
    } catch {
        copied.value = null;
    }
};

onMounted(() => {
    track('landing_view', {
        metadata: {
            action_type: 'robinhood_chain_landing',
            network: 'Robinhood Chain',
        },
    });
});
</script>

<template>
    <Head :title="props.seo.title">
        <meta
            head-key="description"
            name="description"
            :content="props.seo.description"
        />
        <link
            head-key="canonical"
            rel="canonical"
            :href="props.seo.canonical"
        />
        <meta
            head-key="og:title"
            property="og:title"
            :content="props.seo.title"
        />
        <meta
            head-key="og:description"
            property="og:description"
            content="Verified Cyberia routes for Robinhood Chain: bridge supported assets, trade, provide liquidity and stake eligible LP tokens."
        />
        <meta
            head-key="og:url"
            property="og:url"
            :content="props.seo.canonical"
        />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta
            head-key="og:image"
            property="og:image"
            :content="props.seo.image"
        />
        <meta
            head-key="twitter:card"
            name="twitter:card"
            content="summary_large_image"
        />
        <meta
            head-key="twitter:title"
            name="twitter:title"
            :content="props.seo.title"
        />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            content="Connect, switch to Robinhood Chain, bridge supported assets, trade and use live liquidity infrastructure."
        />
        <script head-key="faq-json" type="application/ld+json">
            {{ faqJson }}
        </script>
    </Head>

    <PageShell size="wide">
        <template #hero>
            <PageHero
                eyebrow="Robinhood Chain · Chain ID 4663"
                title="Bridge, trade and earn with Robinhood Chain"
                description="Cyberia connects a live bridge corridor with Ritual swaps, liquidity and funded LP farms. Every executable action below points to an on-chain application."
            >
                <template #actions>
                    <Badge variant="outline" class="gap-1.5">
                        <span
                            class="size-1.5 rounded-full bg-emerald-500"
                        ></span>
                        Live contracts
                    </Badge>
                </template>
            </PageHero>
        </template>

        <section
            class="grid gap-3 rounded-xl border border-border/70 bg-card p-4 sm:grid-cols-2 lg:grid-cols-4"
            aria-label="Verified Robinhood Chain support"
        >
            <div>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Network
                </p>
                <p class="mt-1 font-semibold">Robinhood Chain mainnet</p>
                <p class="text-xs text-muted-foreground">
                    Chain ID 4663 · ETH gas
                </p>
            </div>
            <div>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    DEX assets
                </p>
                <p class="mt-1 font-semibold">
                    {{ ROBINHOOD_GROWTH.dex.tokens.join(' · ') }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Curated live interface
                </p>
            </div>
            <div>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Yield products
                </p>
                <p class="mt-1 font-semibold">2 funded LP farms</p>
                <p class="text-xs text-muted-foreground">
                    Variable ASH rewards
                </p>
            </div>
            <div>
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    Settlement
                </p>
                <p class="mt-1 flex items-center gap-2 font-semibold">
                    <ShieldCheck class="size-4 text-brand-cyan" />
                    On-chain
                </p>
                <a
                    :href="ROBINHOOD_GROWTH.dex.explorer"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground underline-offset-4 hover:underline"
                >
                    Open explorer <ExternalLink class="size-3" />
                </a>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="route-heading">
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
                >
                    First transaction
                </p>
                <h2 id="route-heading" class="mt-2 text-2xl font-bold">
                    A direct five-step route
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Start with a wallet, verify the network, then use only the
                    actions currently enabled by Cyberia’s contracts and
                    relayer.
                </p>
            </div>

            <div class="mt-6 grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs text-brand-cyan">01</p>
                                <CardTitle class="mt-1"
                                    >Connect wallet</CardTitle
                                >
                            </div>
                            <Wallet class="size-5 text-muted-foreground" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            Connect an EVM wallet. No signature or transaction
                            is requested until you start an on-chain action.
                        </p>
                        <Button
                            class="mt-5 w-full sm:w-auto"
                            :disabled="wallet.isConnecting.value"
                            @click="connectWallet"
                        >
                            <Loader2
                                v-if="wallet.isConnecting.value"
                                class="mr-2 size-4 animate-spin"
                            />
                            {{
                                wallet.isConnected.value
                                    ? 'Wallet connected'
                                    : 'Connect wallet'
                            }}
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs text-brand-cyan">02</p>
                                <CardTitle class="mt-1"
                                    >Switch to Robinhood Chain</CardTitle
                                >
                            </div>
                            <Network class="size-5 text-muted-foreground" />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            The app uses the configured mainnet RPC, ETH gas
                            currency and Blockscout explorer for chain ID 4663.
                        </p>
                        <Button
                            variant="outline"
                            class="mt-5 w-full sm:w-auto"
                            :disabled="switchingNetwork"
                            @click="switchToRobinhood"
                        >
                            <Loader2
                                v-if="switchingNetwork"
                                class="mr-2 size-4 animate-spin"
                            />
                            Add or switch network
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <p
                v-if="wallet.error.value"
                role="alert"
                class="mt-3 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive"
            >
                {{ wallet.error.value }}
            </p>
        </section>

        <section class="mt-12 grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader>
                    <p class="text-xs text-brand-cyan">03</p>
                    <CardTitle>Bridge a supported asset</CardTitle>
                </CardHeader>
                <CardContent class="flex h-full flex-col">
                    <template v-if="liveInbound">
                        <p class="text-sm text-muted-foreground">
                            The live route is
                            <strong class="text-foreground"
                                >Robinhood Chain → Cyberia</strong
                            >
                            for {{ liveInbound.tokens.join(', ') }}.
                        </p>
                        <p class="mt-3 text-xs text-muted-foreground">
                            Cyberia → Robinhood Chain:
                            {{ outbound?.unavailableReason ?? 'not enabled' }}.
                            No outbound transaction is offered while this route
                            is unavailable.
                        </p>
                        <Button as-child class="mt-5 w-full">
                            <a :href="attributedUrl(bridge().url)">
                                Open Robinhood bridge
                                <ArrowRight class="ml-2 size-4" />
                            </a>
                        </Button>
                    </template>
                    <EmptyState
                        v-else
                        title="No live Robinhood bridge route"
                        description="The interface hides execution when server-side bridge configuration is unavailable."
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <p class="text-xs text-brand-cyan">04</p>
                    <CardTitle>Trade through Ritual DEX</CardTitle>
                </CardHeader>
                <CardContent class="flex h-full flex-col">
                    <p class="text-sm text-muted-foreground">
                        Select Robinhood Chain inside Swap, then trade against
                        the live ETH/CYBER or ETH/ASH liquidity graph. Review
                        price impact before confirming.
                    </p>
                    <Button as-child class="mt-5 w-full">
                        <a :href="attributedUrl(swap().url)">
                            Trade on Robinhood Chain
                            <ArrowRight class="ml-2 size-4" />
                        </a>
                    </Button>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <p class="text-xs text-brand-cyan">05</p>
                    <CardTitle>Provide liquidity or farm</CardTitle>
                </CardHeader>
                <CardContent class="flex h-full flex-col">
                    <p class="text-sm text-muted-foreground">
                        Add liquidity first, then stake an eligible ETH/CYBER or
                        ETH/ASH LP token in the funded farm. APY is variable and
                        is not guaranteed.
                    </p>
                    <div class="mt-5 grid gap-2">
                        <Button as-child>
                            <a :href="attributedUrl(liquidity().url)">
                                Add liquidity
                                <ArrowRight class="ml-2 size-4" />
                            </a>
                        </Button>
                        <Button as-child variant="outline">
                            <a :href="attributedUrl(farm().url)">
                                View Robinhood farms
                                <ArrowRight class="ml-2 size-4" />
                            </a>
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </section>

        <section class="mt-12" aria-labelledby="contracts-heading">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p
                        class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
                    >
                        Verify
                    </p>
                    <h2 id="contracts-heading" class="mt-2 text-2xl font-bold">
                        Robinhood Chain contracts
                    </h2>
                </div>
                <a
                    :href="ROBINHOOD_GROWTH.dex.explorer"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-sm underline-offset-4 hover:underline"
                >
                    Blockscout <ExternalLink class="size-4" />
                </a>
            </div>

            <div
                class="mt-5 overflow-hidden rounded-xl border border-border/70"
            >
                <div
                    v-for="contract in contracts"
                    :key="contract.address"
                    class="grid gap-3 border-b border-border/70 p-4 last:border-b-0 sm:grid-cols-[11rem_1fr_auto]"
                >
                    <p class="font-medium">{{ contract.label }}</p>
                    <a
                        :href="contract.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 font-mono text-xs break-all text-muted-foreground hover:text-foreground"
                    >
                        {{ contract.address }}
                    </a>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="justify-self-start sm:justify-self-end"
                        :aria-label="`Copy ${contract.label} address`"
                        @click="copyAddress(contract.address)"
                    >
                        <CheckCircle2
                            v-if="copied === contract.address"
                            class="mr-2 size-4 text-emerald-500"
                        />
                        <Copy v-else class="mr-2 size-4" />
                        {{ copied === contract.address ? 'Copied' : 'Copy' }}
                    </Button>
                </div>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="faq-heading">
            <h2 id="faq-heading" class="text-2xl font-bold">
                Robinhood Chain FAQ
            </h2>
            <div class="mt-5 grid gap-3 md:grid-cols-2">
                <Card v-for="item in props.faq" :key="item.question">
                    <CardHeader>
                        <CardTitle class="text-base">{{
                            item.question
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            {{ item.answer }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </section>
    </PageShell>
</template>
