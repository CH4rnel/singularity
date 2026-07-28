<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Copy,
    ExternalLink,
    ShieldAlert,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import TokenIcon from '@/components/TokenIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import EmptyState from '@/components/web3/EmptyState.vue';
import PageHero from '@/components/web3/PageHero.vue';
import PageShell from '@/components/web3/PageShell.vue';
import { PARTNER_CAMPAIGNS } from '@/lib/growthCampaigns';
import type {
    PartnerAction,
    PartnerCampaign,
    PartnerSlug,
} from '@/lib/growthCampaigns';
import { attributedUrl, track } from '@/lib/track';
import { home } from '@/routes';

const props = defineProps<{
    partnerSlug: string;
}>();

const campaign = computed<PartnerCampaign | null>(
    () => PARTNER_CAMPAIGNS[props.partnerSlug as PartnerSlug] ?? null,
);
const copied = ref<string | null>(null);

const pageTitle = computed(() =>
    campaign.value
        ? `${campaign.value.name} on Cyberia`
        : 'Cyberia partner not found',
);
const pageDescription = computed(() =>
    campaign.value
        ? `${campaign.value.symbol} bridge, trade, liquidity, staking and lending actions that are currently available through Cyberia.`
        : 'The requested Cyberia partner campaign is not configured.',
);
const canonicalUrl = computed(
    () => `https://cyberia.church/partners/${props.partnerSlug}`,
);

const trackPartnerAction = (action: PartnerAction): void => {
    if (!campaign.value) {
        return;
    }

    track('partner_cta_clicked', {
        metadata: {
            action_type: action.type,
            network: action.network,
            partner: campaign.value.slug,
            token: campaign.value.symbol,
        },
    });
};

const trackPartnerWebsite = (): void => {
    if (!campaign.value) {
        return;
    }

    track('partner_cta_clicked', {
        metadata: {
            action_type: 'partner_website',
            partner: campaign.value.slug,
            token: campaign.value.symbol,
        },
    });
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
    if (!campaign.value) {
        return;
    }

    track('landing_view', {
        metadata: {
            action_type: 'partner_landing',
            partner: campaign.value.slug,
            token: campaign.value.symbol,
        },
    });
});
</script>

<template>
    <Head :title="pageTitle">
        <meta
            head-key="description"
            name="description"
            :content="pageDescription"
        />
        <link head-key="canonical" rel="canonical" :href="canonicalUrl" />
        <meta
            head-key="og:title"
            property="og:title"
            :content="`${pageTitle} | Cyberia`"
        />
        <meta
            head-key="og:description"
            property="og:description"
            :content="pageDescription"
        />
        <meta head-key="og:url" property="og:url" :content="canonicalUrl" />
        <meta head-key="og:type" property="og:type" content="website" />
        <meta
            v-if="campaign"
            head-key="og:image"
            property="og:image"
            :content="`https://cyberia.church${campaign.logo}`"
        />
        <meta head-key="twitter:card" name="twitter:card" content="summary" />
        <meta
            head-key="twitter:title"
            name="twitter:title"
            :content="`${pageTitle} | Cyberia`"
        />
        <meta
            head-key="twitter:description"
            name="twitter:description"
            :content="pageDescription"
        />
    </Head>

    <PageShell v-if="campaign" size="wide">
        <template #hero>
            <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-center">
                <TokenIcon
                    :symbol="campaign.symbol"
                    :logo="campaign.logo"
                    :size="72"
                    ring
                />
                <PageHero
                    :eyebrow="`Cyberia partner · ${campaign.symbol}`"
                    :title="`${campaign.name} on Cyberia`"
                    :description="campaign.description"
                    class="mb-0 flex-1"
                >
                    <template #actions>
                        <Button as-child variant="outline" size="sm">
                            <a
                                :href="campaign.website"
                                target="_blank"
                                rel="noopener noreferrer"
                                @click="trackPartnerWebsite"
                            >
                                Partner website
                                <ExternalLink class="ml-2 size-4" />
                            </a>
                        </Button>
                    </template>
                </PageHero>
            </div>
        </template>

        <section
            class="flex flex-wrap items-center gap-2 rounded-xl border border-border/70 bg-card p-4"
            aria-label="Supported networks"
        >
            <span
                class="mr-2 text-xs tracking-widest text-muted-foreground uppercase"
            >
                Supported networks
            </span>
            <Badge
                v-for="network in campaign.networks"
                :key="network"
                variant="outline"
            >
                {{ network }}
            </Badge>
            <span class="ml-auto text-xs text-muted-foreground">
                All listed actions settle on-chain
            </span>
        </section>

        <section class="mt-12" aria-labelledby="actions-heading">
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
                >
                    Available now
                </p>
                <h2 id="actions-heading" class="mt-2 text-2xl font-bold">
                    Choose an on-chain action
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    This page only exposes products configured for
                    {{ campaign.symbol }}. The destination app will ask you to
                    connect a wallet and confirm the required network.
                </p>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card
                    v-for="action in campaign.actions"
                    :key="`${action.type}:${action.network}`"
                    class="flex flex-col"
                >
                    <CardHeader>
                        <div class="flex items-start justify-between gap-3">
                            <CardTitle class="text-lg">{{
                                action.label
                            }}</CardTitle>
                            <Badge
                                variant="outline"
                                class="max-w-40 text-right"
                            >
                                {{ action.network }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="flex flex-1 flex-col">
                        <p class="flex-1 text-sm text-muted-foreground">
                            {{ action.description }}
                        </p>
                        <Button as-child class="mt-5 w-full">
                            <a
                                :href="attributedUrl(action.href)"
                                @click="trackPartnerAction(action)"
                            >
                                {{ action.label }}
                                <ArrowRight class="ml-2 size-4" />
                            </a>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="contracts-heading">
            <div>
                <p
                    class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
                >
                    Verify before acting
                </p>
                <h2 id="contracts-heading" class="mt-2 text-2xl font-bold">
                    Contract addresses
                </h2>
            </div>

            <div
                class="mt-5 overflow-hidden rounded-xl border border-border/70"
            >
                <div
                    v-for="contract in campaign.contracts"
                    :key="`${contract.network}:${contract.address}`"
                    class="grid gap-3 border-b border-border/70 p-4 last:border-b-0 md:grid-cols-[10rem_9rem_1fr_auto]"
                >
                    <p class="font-medium">{{ contract.network }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ contract.standard }}
                    </p>
                    <a
                        :href="contract.explorerUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="min-w-0 font-mono text-xs break-all text-muted-foreground hover:text-foreground"
                    >
                        {{ contract.address }}
                    </a>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="justify-self-start md:justify-self-end"
                        :aria-label="`Copy ${campaign.symbol} address on ${contract.network}`"
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

        <aside
            class="mt-12 flex gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-5"
        >
            <ShieldAlert class="mt-0.5 size-5 shrink-0 text-amber-500" />
            <div>
                <h2 class="font-semibold">DeFi risk and variable rewards</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    APY is not fixed or guaranteed. Token prices, liquidity,
                    reward funding, smart-contract risk, impermanent loss and
                    bridge inventory can all change. Verify addresses in the
                    explorer and review each wallet transaction before signing.
                </p>
            </div>
        </aside>
    </PageShell>

    <PageShell v-else size="narrow">
        <EmptyState
            title="Partner campaign not found"
            description="This partner slug is not present in the typed Cyberia campaign registry."
        >
            <Button as-child>
                <a :href="home().url">Return to Cyberia</a>
            </Button>
        </EmptyState>
    </PageShell>
</template>
