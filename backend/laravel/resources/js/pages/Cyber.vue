<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import PageHero from '@/components/web3/PageHero.vue';
import { formatUsd, formatUsdPrice, shortAddress } from '@/lib/tokenFormat';

type Chain = {
    name: string;
    id: number;
    id_hex: string;
    symbol: string;
    decimals: number;
    block_time: string;
    consensus: string;
    rpc: string;
    explorer: string;
};

type Corridor = {
    token: string;
    from: string;
    to: string;
    open: boolean;
    note: string | null;
};

type Market = {
    price: number | null;
    pools: number | null;
    locked: number | null;
    locked_usd: number | null;
};

const props = defineProps<{
    chain: Chain;
    contracts: Record<string, string>;
    launchpad: { min_cyber: number; lp_burned: boolean };
    corridors: Corridor[];
    market: Market;
}>();

/** Explorer address page — the receipt behind a claim. */
const scan = (address: string) => `${props.chain.explorer}/address/${address}`;

/**
 * Coin amounts, compact. A reserve figure is read for its order of magnitude
 * ("most of the float" vs "a rounding error"), never for its last digit.
 */
const coin = (value: number | null): string =>
    value === null
        ? '—'
        : new Intl.NumberFormat('en-US', {
              notation: value >= 1_000_000 ? 'compact' : 'standard',
              maximumFractionDigits: value >= 1000 ? 0 : 2,
          }).format(value);

const usd = (value: number | null): string =>
    value === null ? '—' : formatUsd(value);
</script>

<template>
    <Head title="$CYBER" />

    <div class="mx-auto max-w-5xl space-y-14 p-6">
        <PageHero
            eyebrow="The coin"
            title="$CYBER"
            :description="`The native coin of the ${chain.name} chain. Every claim on this page names the contract that enforces it, so none of it has to be taken on trust.`"
        >
            <template #actions>
                <a
                    :href="chain.explorer"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1 rounded border border-border px-3 py-1.5 text-sm transition hover:border-input"
                >
                    Explorer <ExternalLink class="h-3.5 w-3.5" />
                </a>
            </template>
        </PageHero>

        <!-- Live figures. Anything the pool snapshot cannot answer renders as
             "—", never as a zero: an unmeasured reserve and an empty one look
             the same on a dashboard and only one of them is bad news. -->
        <section
            class="grid gap-px overflow-hidden rounded border border-border bg-border sm:grid-cols-4"
        >
            <div class="bg-background p-4">
                <p class="text-xs text-muted-foreground">Price</p>
                <p class="mt-1 font-mono text-lg font-semibold">
                    {{
                        market.price !== null
                            ? formatUsdPrice(market.price)
                            : '—'
                    }}
                </p>
            </div>
            <div class="bg-background p-4">
                <p class="text-xs text-muted-foreground">
                    Pools quoted in CYBER
                </p>
                <p class="mt-1 font-mono text-lg font-semibold">
                    {{ market.pools ?? '—' }}
                </p>
            </div>
            <div class="bg-background p-4">
                <p class="text-xs text-muted-foreground">
                    CYBER in those pools
                </p>
                <p class="mt-1 font-mono text-lg font-semibold">
                    {{ coin(market.locked) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ usd(market.locked_usd) }}
                </p>
            </div>
            <div class="bg-background p-4">
                <p class="text-xs text-muted-foreground">Chain ID</p>
                <p class="mt-1 font-mono text-lg font-semibold">
                    {{ chain.id }}
                </p>
                <p class="font-mono text-xs text-muted-foreground">
                    {{ chain.id_hex }}
                </p>
            </div>
        </section>

        <!-- Disambiguation first. Three assets share this name and mixing them
             up is the single most common misreading of the ecosystem. -->
        <section class="space-y-4">
            <h2 class="text-xl font-bold">Three things are called CYBER</h2>
            <p class="max-w-2xl text-sm text-muted-foreground">
                They are not interchangeable and only the first one is the coin
                this page is about.
            </p>

            <div class="space-y-3">
                <div class="rounded border border-brand-cyan/40 p-4">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <h3 class="font-semibold">CYBER</h3>
                        <span
                            class="rounded bg-brand-cyan/10 px-2 py-0.5 text-xs text-brand-cyan"
                        >
                            the coin
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        The chain's native coin, the way ETH is Ethereum's. It
                        has no contract address because it is not a contract —
                        it is the balance the protocol itself tracks, and the
                        only thing a fee can be paid in.
                    </p>
                </div>

                <div class="rounded border border-border p-4">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <h3 class="font-semibold">WCYBER</h3>
                        <span
                            class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            the wrapper
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        The ERC-20 wrapper around the coin, redeemable 1:1 at
                        any time — WETH's relationship to ETH. Pools, farms and
                        lending markets hold WCYBER because a contract cannot
                        hold the raw coin. When this page counts CYBER in
                        liquidity, it is counting this.
                    </p>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs">
                        <Link
                            :href="`/token/${contracts.wcyber}`"
                            class="text-brand-cyan hover:underline"
                        >
                            Token page
                        </Link>
                        <a
                            :href="scan(contracts.wcyber)"
                            target="_blank"
                            rel="noopener"
                            class="font-mono text-muted-foreground hover:underline"
                        >
                            {{ shortAddress(contracts.wcyber) }}
                        </a>
                    </div>
                </div>

                <div class="rounded border border-border p-4">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <h3 class="font-semibold">CYBER.sol</h3>
                        <span
                            class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                        >
                            a different asset
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        A Solana-issued token that carries the brand and trades
                        on pump.fun, bridged onto this chain as its own ERC-20
                        with its own supply. It is not the coin and it does not
                        pay for gas. It converts to CYBER at a fixed rate.
                    </p>
                    <div class="mt-2 flex flex-wrap gap-3 text-xs">
                        <Link
                            href="/convert"
                            class="text-brand-cyan hover:underline"
                        >
                            Convert
                        </Link>
                        <a
                            :href="scan(contracts.cyber_sol)"
                            target="_blank"
                            rel="noopener"
                            class="font-mono text-muted-foreground hover:underline"
                        >
                            {{ shortAddress(contracts.cyber_sol) }}
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- The affirmative half. One card per mechanism, each with its
             contract, because a utility with no address is a promise. -->
        <section class="space-y-4">
            <h2 class="text-xl font-bold">
                What the chain cannot do without it
            </h2>

            <article class="rounded border border-border p-5">
                <h3 class="font-semibold">It pays for every transaction</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    A fee on {{ chain.name }} is payable in CYBER and in nothing
                    else. This is the hard one: an address holding only
                    stablecoins cannot move them, because moving them costs a
                    coin it does not have. Blocks seal about every
                    {{ chain.block_time }}, so the cost of a transfer is small —
                    but it is never zero and never denominated in anything else.
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    The gas station exists for exactly that dead end: it sends a
                    fixed drip of CYBER to an address that qualifies, and the
                    user then signs their own transaction unchanged. It removes
                    the obstacle without removing the demand — the fee is still
                    paid, still in CYBER.
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <Link href="/wallet" class="text-brand-cyan hover:underline"
                        >Wallet</Link
                    >
                    <a
                        :href="scan(contracts.gas_station)"
                        target="_blank"
                        rel="noopener"
                        class="font-mono text-muted-foreground hover:underline"
                    >
                        Gas station {{ shortAddress(contracts.gas_station) }}
                    </a>
                </div>
            </article>

            <article class="rounded border border-border p-5">
                <h3 class="font-semibold">
                    It is what the market quotes against
                </h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    The DEX routes through WCYBER: it is the hub most pairs are
                    quoted in, so a trade between two other assets usually
                    passes through the coin. Right now
                    <strong class="text-foreground">{{
                        market.pools ?? '—'
                    }}</strong>
                    pools are quoted against it, holding
                    <strong class="text-foreground">{{
                        coin(market.locked)
                    }}</strong>
                    CYBER ({{ usd(market.locked_usd) }}) as their reserve. That
                    liquidity is supplied by holders, not by the treasury, and
                    it can leave — which is why this figure is read live rather
                    than written into the page.
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <Link href="/swap" class="text-brand-cyan hover:underline"
                        >Swap</Link
                    >
                    <Link
                        href="/liquidity"
                        class="text-brand-cyan hover:underline"
                        >Liquidity</Link
                    >
                    <Link
                        href="/analytics"
                        class="text-brand-cyan hover:underline"
                        >Analytics</Link
                    >
                </div>
            </article>

            <article class="rounded border border-border p-5">
                <h3 class="font-semibold">It is what a token launch costs</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    Launching a token here is a fair launch: at least
                    <strong class="text-foreground"
                        >{{ launchpad.min_cyber }} CYBER</strong
                    >
                    is paired against 100% of the new token's supply, and the LP
                    tokens are sent to the burn address in the same call. Nobody
                    — including the launcher and including us — can ever
                    withdraw that position. The coin stays in the pool as the
                    reserve everyone trades against.
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    There is no team allocation and no vesting schedule to
                    describe, because the creator keeps none of the supply.
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <Link
                        href="/launchpad"
                        class="text-brand-cyan hover:underline"
                        >Launchpad</Link
                    >
                    <a
                        :href="scan(contracts.launchpad)"
                        target="_blank"
                        rel="noopener"
                        class="font-mono text-muted-foreground hover:underline"
                    >
                        {{ shortAddress(contracts.launchpad) }}
                    </a>
                </div>
            </article>

            <article class="rounded border border-border p-5">
                <h3 class="font-semibold">It is collateral</h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    WCYBER is a live market in the lending protocol: it can be
                    supplied to earn interest and borrowed against, so holding
                    the coin and needing stablecoins is not a reason to sell it.
                </p>
                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <Link
                        href="/lending"
                        class="text-brand-cyan hover:underline"
                        >Lending</Link
                    >
                    <a
                        :href="scan(contracts.lending_wcyber_market)"
                        target="_blank"
                        rel="noopener"
                        class="font-mono text-muted-foreground hover:underline"
                    >
                        Market
                        {{ shortAddress(contracts.lending_wcyber_market) }}
                    </a>
                    <a
                        :href="scan(contracts.lending_comptroller)"
                        target="_blank"
                        rel="noopener"
                        class="font-mono text-muted-foreground hover:underline"
                    >
                        Comptroller
                        {{ shortAddress(contracts.lending_comptroller) }}
                    </a>
                </div>
            </article>

            <article class="rounded border border-border p-5">
                <h3 class="font-semibold">
                    It leaves the chain and comes back
                </h3>
                <p class="mt-2 text-sm text-muted-foreground">
                    The coin exists on other networks as a bridged
                    representation, so it is not trapped behind one RPC
                    endpoint. A deposit is verified on the source chain before
                    anything is paid out on the destination, and the relayer
                    never mints against an unconfirmed transfer.
                </p>
                <p class="mt-2 text-sm text-muted-foreground">
                    Not every lane is open in both directions, so the list is
                    read from the running bridge rather than written down here:
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                    <li
                        v-for="corridor in corridors"
                        :key="`${corridor.token}-${corridor.from}-${corridor.to}`"
                        class="flex flex-wrap items-baseline gap-2"
                    >
                        <span class="font-mono text-xs text-foreground">{{
                            corridor.token
                        }}</span>
                        <span class="text-muted-foreground">
                            {{ corridor.from }} → {{ corridor.to }}
                        </span>
                        <span
                            v-if="corridor.open"
                            class="rounded bg-brand-cyan/10 px-1.5 py-0.5 text-xs text-brand-cyan"
                        >
                            open
                        </span>
                        <span
                            v-else
                            class="rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground"
                        >
                            {{ corridor.note ?? 'closed' }}
                        </span>
                    </li>
                    <li v-if="!corridors.length" class="text-muted-foreground">
                        No corridor is currently carrying the coin.
                    </li>
                </ul>
                <div class="mt-3 flex flex-wrap gap-3 text-xs">
                    <Link href="/bridge" class="text-brand-cyan hover:underline"
                        >Bridge</Link
                    >
                    <a
                        :href="scan(contracts.bridge)"
                        target="_blank"
                        rel="noopener"
                        class="font-mono text-muted-foreground hover:underline"
                    >
                        {{ shortAddress(contracts.bridge) }}
                    </a>
                </div>
            </article>
        </section>

        <section class="space-y-4">
            <h2 class="text-xl font-bold">How to get some</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <Link
                    href="/swap"
                    class="rounded border border-border p-4 transition hover:border-input"
                >
                    <p class="font-semibold">Buy it on the DEX</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Trade any listed asset into CYBER through the pools
                        above.
                    </p>
                </Link>
                <Link
                    href="/bridge"
                    class="rounded border border-border p-4 transition hover:border-input"
                >
                    <p class="font-semibold">Bridge in</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Move assets from another network and trade them here.
                    </p>
                </Link>
                <Link
                    href="/convert"
                    class="rounded border border-border p-4 transition hover:border-input"
                >
                    <p class="font-semibold">Convert CYBER.sol</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Redeem the Solana-issued token for the native coin.
                    </p>
                </Link>
                <Link
                    href="/wallet"
                    class="rounded border border-border p-4 transition hover:border-input"
                >
                    <p class="font-semibold">Hold it yourself</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        A non-custodial wallet that already knows this chain.
                    </p>
                </Link>
            </div>
        </section>

        <section class="rounded border border-border p-5">
            <h2 class="font-semibold">Check it</h2>
            <p class="mt-2 text-sm text-muted-foreground">
                Every address on this page links into the block explorer, and
                the figures are read from the live pool graph rather than typed
                in. If something here cannot be verified there, it should not be
                here — tell us and it comes down.
            </p>
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-muted-foreground">Network</dt>
                    <dd class="font-mono">
                        {{ chain.name }} · {{ chain.id }} ({{ chain.id_hex }})
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Currency</dt>
                    <dd class="font-mono">
                        {{ chain.symbol }} · {{ chain.decimals }} decimals
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">RPC</dt>
                    <dd class="font-mono break-all">{{ chain.rpc }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Consensus</dt>
                    <dd class="font-mono">
                        {{ chain.consensus }} · {{ chain.block_time }} blocks
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
