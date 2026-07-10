<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    ChartColumn,
    CircleUser,
    Coins,
    Dices,
    Droplets,
    Folder,
    Globe,
    Grid3x3,
    HandCoins,
    Link as LinkIcon,
    Repeat2,
    Rocket,
    Sprout,
    Store,
    TrendingUp,
    Users,
    Vote,
    Waypoints,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import type { Auth } from '@/types/auth';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    }),
});

const page = usePage<{ auth: Auth }>();
const user = computed(() => page.props.auth.user);

const displayName = computed(
    () =>
        user.value.name ||
        (user.value.wallet_address
            ? user.value.wallet_address.slice(0, 6) +
              '…' +
              user.value.wallet_address.slice(-4)
            : `User #${user.value.id}`),
);

type SectionCard = {
    title: string;
    description: string;
    href: string;
    icon: unknown;
};

type Section = {
    label: string;
    cards: SectionCard[];
};

const sections: Section[] = [
    {
        label: 'DeFi',
        cards: [
            {
                title: 'Bridge',
                description:
                    'Move assets between Cyberia, Solana, TON, BNB, Base, BTC, LTC, YTN and XMR.',
                href: '/bridge',
                icon: Waypoints,
            },
            {
                title: 'Swap',
                description: 'Trade tokens on the Ritual DEX.',
                href: '/swap',
                icon: ArrowRightLeft,
            },
            {
                title: 'Liquidity',
                description: 'Add or remove liquidity in DEX pools.',
                href: '/liquidity',
                icon: Droplets,
            },
            {
                title: 'Farm',
                description: 'Stake LP tokens, earn ASH.',
                href: '/farm',
                icon: Sprout,
            },
            {
                title: 'Lending',
                description: 'Supply collateral and borrow assets.',
                href: '/lending',
                icon: HandCoins,
            },
            {
                title: 'Convert',
                description: 'Redeem CYBER.sol for native CYBER.',
                href: '/convert',
                icon: Repeat2,
            },
            {
                title: 'Launchpad',
                description: 'Launch a token with burned-in liquidity.',
                href: '/launchpad',
                icon: Rocket,
            },
        ],
    },
    {
        label: 'Explore',
        cards: [
            {
                title: 'Tokens',
                description: 'Directory of every token on Cyberia.',
                href: '/tokens',
                icon: Coins,
            },
            {
                title: 'Analytics',
                description: 'Volumes, pools and on-chain activity.',
                href: '/analytics',
                icon: ChartColumn,
            },
            {
                title: 'NFT Market',
                description: 'Trade CyberiaNFTs.',
                href: '/market',
                icon: Store,
            },
            {
                title: 'Fediverse',
                description: 'Resolve ActivityPub handles and profiles.',
                href: '/fediverse',
                icon: Globe,
            },
        ],
    },
    {
        label: 'Games',
        cards: [
            {
                title: 'Slots',
                description: 'The one-armed bandit, on-chain.',
                href: '/slots',
                icon: Dices,
            },
            {
                title: 'Predictions',
                description: 'Bet on price moves and events.',
                href: '/predictions',
                icon: TrendingUp,
            },
            {
                title: 'Pixel Battle',
                description: 'Claim pixels on the shared canvas.',
                href: '/pixels',
                icon: Grid3x3,
            },
        ],
    },
    {
        label: 'Workspace',
        cards: [
            {
                title: 'DAO',
                description: 'Proposals, voting and governance.',
                href: '/dao',
                icon: Vote,
            },
            {
                title: 'CRM',
                description: 'Contacts and notes for your team.',
                href: '/crm',
                icon: Users,
            },
            {
                title: 'Links',
                description: 'Save and organize bookmarks.',
                href: '/links',
                icon: LinkIcon,
            },
            {
                title: 'Categories',
                description: 'Group your links by topic.',
                href: '/categories',
                icon: Folder,
            },
        ],
    },
];
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto p-4">
        <!-- Welcome -->
        <header
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-6 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border"
        >
            <div>
                <h1 class="text-xl font-extrabold tracking-tight">
                    Welcome back, {{ displayName }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Everything in the Cyberia ecosystem, one hop away.
                </p>
            </div>
            <Link
                href="/profile"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg border border-border/70 px-4 py-2 text-sm font-medium hover:bg-accent"
            >
                <CircleUser class="h-4 w-4" />
                Profile, deposits & achievements
            </Link>
        </header>

        <!-- Sections -->
        <section
            v-for="section in sections"
            :key="section.label"
            class="space-y-3"
        >
            <h2
                class="text-sm font-semibold tracking-widest text-muted-foreground uppercase"
            >
                {{ section.label }}
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <Link
                    v-for="card in section.cards"
                    :key="card.href"
                    :href="card.href"
                    class="group flex items-start gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 transition-colors hover:border-brand-cyan/50 hover:bg-accent/50 dark:border-sidebar-border"
                >
                    <component
                        :is="card.icon"
                        class="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground transition-colors group-hover:text-brand-cyan"
                    />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ card.title }}</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ card.description }}
                        </p>
                    </div>
                </Link>
            </div>
        </section>
    </div>
</template>
