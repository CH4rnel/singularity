<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CalendarDays,
    CircleDashed,
    ExternalLink,
    ShieldCheck,
} from 'lucide-vue-next';
import { onMounted } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import PageHero from '@/components/web3/PageHero.vue';
import PageShell from '@/components/web3/PageShell.vue';
import { track } from '@/lib/track';
import { show as profile } from '@/routes/profile';

const missions = [
    {
        title: 'Connect a wallet',
        description:
            'Establish the wallet identity used to reconcile later on-chain actions.',
        verifier: 'Wallet ownership signature + linked profile',
    },
    {
        title: 'Complete a supported bridge',
        description:
            'Settle a bridge request on both the source and destination sides.',
        verifier: 'Completed bridge_requests record + transaction hashes',
    },
    {
        title: 'Complete a swap',
        description: 'Execute a real Ritual swap above the campaign minimum.',
        verifier: 'Indexed Ritual Swap event',
    },
    {
        title: 'Add liquidity or stake',
        description:
            'Provide liquidity or deposit an eligible token/LP position.',
        verifier: 'Indexed liq_add or stake event',
    },
    {
        title: 'Return on another day',
        description:
            'Complete another qualifying on-chain action on a later UTC date.',
        verifier: 'Distinct-day indexed event',
    },
];

onMounted(() => {
    track('landing_view', {
        metadata: {
            action_type: 'pioneer_season_preview',
            campaign: 'cyberia_pioneer_season',
        },
    });
});
</script>

<template>
    <Head title="Cyberia Pioneer Season — Coming soon">
        <meta
            head-key="description"
            name="description"
            content="Preview the Cyberia Pioneer Season: an upcoming badge and points campaign based on verified bridge, swap, liquidity, staking and repeat on-chain activity."
        />
        <link
            head-key="canonical"
            rel="canonical"
            href="https://cyberia.church/pioneer-season"
        />
        <meta
            head-key="robots"
            name="robots"
            content="index,follow,max-image-preview:large"
        />
    </Head>

    <PageShell size="wide">
        <template #hero>
            <PageHero
                eyebrow="Campaign preview"
                title="Cyberia Pioneer Season"
                description="A planned activation campaign based on real on-chain use. Mission completion is disabled until the server-side verifier, minimums and abuse controls are deployed."
            >
                <template #actions>
                    <Badge variant="outline" class="gap-2 border-amber-500/40">
                        <CircleDashed class="size-3.5 text-amber-500" />
                        Coming soon
                    </Badge>
                </template>
            </PageHero>
        </template>

        <section
            class="grid gap-3 rounded-xl border border-border/70 bg-card p-5 md:grid-cols-3"
        >
            <div class="flex gap-3">
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div>
                    <p class="font-medium">Server verified</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Client claims will never unlock missions.
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                <BadgeCheck class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div>
                    <p class="font-medium">Status, not token promises</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Planned rewards are badges, Pioneer status or points.
                    </p>
                </div>
            </div>
            <div class="flex gap-3">
                <CalendarDays class="mt-0.5 size-5 shrink-0 text-brand-cyan" />
                <div>
                    <p class="font-medium">Retention-aware</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        A later-day action is required, not just a first click.
                    </p>
                </div>
            </div>
        </section>

        <section class="mt-12" aria-labelledby="missions-heading">
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-widest text-brand-cyan uppercase"
                >
                    Planned missions
                </p>
                <h2 id="missions-heading" class="mt-2 text-2xl font-bold">
                    Five verifiable checkpoints
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Social follows and reposts are intentionally excluded. Exact
                    minimum amounts will be published only after liquidity and
                    fee impact are reviewed.
                </p>
            </div>

            <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="(mission, index) in missions" :key="mission.title">
                    <CardHeader>
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-mono text-xs text-brand-cyan">
                                {{ String(index + 1).padStart(2, '0') }}
                            </p>
                            <Badge variant="secondary">Locked</Badge>
                        </div>
                        <CardTitle class="text-lg">{{
                            mission.title
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-sm text-muted-foreground">
                            {{ mission.description }}
                        </p>
                        <p class="mt-4 border-t border-border/70 pt-4 text-xs">
                            <span class="text-muted-foreground">Verifier:</span>
                            {{ mission.verifier }}
                        </p>
                        <Button class="mt-5 w-full" disabled>
                            Coming soon
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section
            class="mt-12 grid gap-6 rounded-xl border border-border/70 bg-muted/30 p-6 lg:grid-cols-[1fr_auto]"
        >
            <div>
                <h2 class="text-xl font-bold">What already exists</h2>
                <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                    Cyberia already records completed bridge requests, indexes
                    swap/liquidity/staking activity and awards several permanent
                    achievements through CyberiaProfile. Pioneer Season still
                    needs campaign-specific event normalization, value minimums,
                    distinct-day logic and sybil review before it can be
                    trusted.
                </p>
            </div>
            <Button as-child variant="outline" class="self-start">
                <a :href="profile().url">
                    View current achievements
                    <ExternalLink class="ml-2 size-4" />
                </a>
            </Button>
        </section>
    </PageShell>
</template>
