<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import NetworkMark from '@/components/wallet/NetworkMark.vue';
import TxList from '@/components/wallet/TxList.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { WALLET_FAMILY_GROUPS, formatUnits } from '@/lib/wallet';
import type { WalletChainId, WalletTxStatus } from '@/lib/wallet';
import { formatUsd, usdValue } from '@/lib/wallet/format';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The home screen: one total, every network, recent movement.
 *
 * Networks are grouped by what the account behind them actually is. All the EVM
 * chains share one address, so they belong together; Solana and Monero each
 * have their own key; the Bitcoin family has one key per coin type. The
 * grouping is the answer to "why do these two show the same string".
 *
 * Every failure a portfolio can have is a designed state here rather than a
 * toast — an unreachable node, a coin with no price, an empty vault. A number
 * that could not be read is never rendered as zero, because zero is a claim
 * about the balance and "—" is a claim about the connection.
 */

const props = defineProps<{
    wallet: MultiWallet;
    prices: Record<string, number | null>;
    /** Chain id → (lowercased contract → USD price). */
    tokenPrices: Record<string, Record<string, number>>;
    online: boolean;
}>();

const emit = defineEmits<{
    open: [chain: WalletChainId];
    send: [];
    receive: [];
    addNetwork: [];
    tokens: [];
    analytics: [];
    accounts: [];
    security: [];
}>();

const { locale, t } = useLocale(walletMessages);

const activeRecord = computed(() => props.wallet.activeAccount.value);

const activeAccountName = computed(() => {
    const record = activeRecord.value;

    if (!record) {
        return t('accountPrimaryName');
    }

    if (record.label?.trim()) {
        return record.label.trim();
    }

    if (record.kind === 'seed') {
        return record.index === 0
            ? t('accountPrimaryName')
            : t('accountSeedName', { index: record.index + 1 });
    }

    return record.kind === 'phrase'
        ? t('accountPhraseName')
        : record.kind === 'key'
          ? t('accountKeyName', { chain: record.chain })
          : t('accountWatchName', { chain: record.chain });
});

const activeAccountKind = computed(() =>
    t(
        {
            seed: 'accountKindSeed',
            phrase: 'accountKindPhrase',
            key: 'accountKindKey',
            watch: 'accountKindWatch',
        }[activeRecord.value?.kind ?? 'seed'],
    ),
);

/**
 * What this account is not covered by, said on the screen that spends from it
 * rather than only on the list it was created in.
 */
const activeAccountWarning = computed(() => {
    const kind = activeRecord.value?.kind;

    return kind === 'watch'
        ? t('accountWatchOnly')
        : kind === 'key'
          ? t('accountNotInBackup')
          : kind === 'phrase'
            ? t('accountOwnPhrase')
            : null;
});

const statusLabels = computed<Record<WalletTxStatus, string>>(() => ({
    confirmed: t('statusConfirmed'),
    pending: t('statusPending'),
    failed: t('statusFailed'),
}));

const cards = computed(() =>
    props.wallet.accounts.value.map((account) => {
        const balance = props.wallet.balances.value[account.chain];
        const price = props.prices[account.chain] ?? null;
        const held = (
            props.wallet.tokens.value[account.chain]?.items ?? []
        ).filter((token) => token.balance > 0n);
        const quotes = props.tokenPrices[account.chain] ?? {};

        // Tokens are summed into the network they live on rather than listed
        // beside it: they share this account and this address, and a portfolio
        // that split them into rows of their own would claim more networks than
        // the seed actually derives.
        const priced = held.filter(
            (token) => quotes[token.address.toLowerCase()] !== undefined,
        );

        return {
            account,
            loading: balance?.loading ?? false,
            error: balance?.error ?? null,
            readable: account.capabilities.balance,
            amount:
                balance?.value === undefined || balance?.value === null
                    ? null
                    : formatUnits(balance.value, account.decimals, 4),
            usd: usdValue(balance?.value ?? null, account.decimals, price),
            tokenCount: held.length,
            unpricedTokens: held.length - priced.length,
            tokenUsd: priced.reduce(
                (sum, token) =>
                    sum +
                    (usdValue(
                        token.balance,
                        token.decimals,
                        quotes[token.address.toLowerCase()],
                    ) ?? 0),
                0,
            ),
        };
    }),
);

const GROUP_LABELS: Record<string, string> = {
    evm: 'groupEvm',
    other: 'groupOther',
    utxo: 'groupUtxo',
};

/**
 * Cards in family order, each carrying the heading its group opens with. A
 * heading is on the first card of a group rather than in a wrapper element so
 * the whole list stays one flat, evenly spaced column.
 */
const groupedCards = computed(() =>
    WALLET_FAMILY_GROUPS.flatMap((group) =>
        cards.value
            .filter((card) => group.families.includes(card.account.family))
            .map((card, index) => ({
                ...card,
                heading: index === 0 ? t(GROUP_LABELS[group.id]) : null,
            })),
    ),
);

/**
 * Failures the user has put away. Dismissal is per network and lasts only
 * until that network reads again: a node that recovers and then fails later
 * has something new to say, and a notice silenced for the whole session would
 * leave a stale balance looking live.
 */
const dismissed = ref(new Set<WalletChainId>());

watch(cards, (list) => {
    for (const card of list) {
        if (card.error === null) {
            dismissed.value.delete(card.account.chain);
        }
    }
});

/** Networks whose balance could not be read at all, minus the ones put away. */
const unreachable = computed(() =>
    cards.value.filter(
        (card) =>
            card.readable &&
            card.error !== null &&
            !dismissed.value.has(card.account.chain),
    ),
);

/** Networks holding value the total cannot include — no price, or no read. */
const unaccounted = computed(
    () =>
        cards.value.filter(
            (card) => card.usd === null || card.unpricedTokens > 0,
        ).length,
);

const total = computed(() =>
    cards.value.reduce((sum, card) => sum + (card.usd ?? 0) + card.tokenUsd, 0),
);

const isEmpty = computed(() =>
    cards.value.every(
        (card) =>
            !card.readable ||
            (card.error === null && !card.loading && card.amount === '0'),
    ),
);

/**
 * An account with nowhere to be: an imported key or watched address whose
 * network has since been removed from this wallet. It is a different thing from
 * an empty vault, and saying "no activity yet" about it would be wrong.
 */
const orphaned = computed(
    () =>
        cards.value.length === 0 &&
        activeRecord.value !== null &&
        activeRecord.value.kind !== 'seed' &&
        activeRecord.value.kind !== 'phrase',
);

/** Recent movement across every chain that can report it, newest first. */
const recent = computed(() =>
    Object.entries(props.wallet.history.value)
        .flatMap(([chain, entry]) =>
            entry.items.map((tx) => ({ chain: chain as WalletChainId, tx })),
        )
        .sort((a, b) => (b.tx.timestamp ?? 0) - (a.tx.timestamp ?? 0))
        .slice(0, 4),
);
</script>

<template>
    <div class="cw-stack">
        <!--
          Which account this whole screen is about. It sits above the total
          rather than beside it because every number below belongs to it, and
          an imported or watched account says so here rather than only in the
          list it came from.
        -->
        <div
            style="
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 22px;
            "
        >
            <button
                type="button"
                class="cw-open"
                style="
                    display: flex;
                    align-items: center;
                    gap: 9px;
                    width: auto;
                "
                @click="emit('accounts')"
            >
                <span
                    style="
                        display: flex;
                        width: 18px;
                        height: 18px;
                        align-items: center;
                        justify-content: center;
                        border: 1px solid var(--cw-accent);
                    "
                >
                    <span
                        style="
                            width: 5px;
                            height: 5px;
                            background: var(--cw-accent);
                        "
                    />
                </span>
                <span>
                    <span
                        style="
                            display: block;
                            font: 500 12px/1.2 var(--cw-sans);
                            color: var(--cw-text);
                        "
                        >{{ activeAccountName }}</span
                    >
                    <span
                        class="cw-label"
                        style="
                            display: block;
                            margin-top: 3px;
                            color: var(--cw-muted);
                        "
                        >{{ activeAccountKind }} ·
                        {{ t('accountSwitch') }}</span
                    >
                </span>
            </button>
            <span class="cw-fill"></span>
            <span
                v-if="activeAccountWarning"
                class="cw-label"
                style="color: var(--cw-pending); text-align: right"
                >{{ activeAccountWarning }}</span
            >
        </div>

        <p v-if="!online" class="cw-note" style="margin-bottom: 18px">
            <span>
                <strong style="display: block; color: var(--cw-text)">{{
                    t('offlineTitle')
                }}</strong>
                {{ t('offlineBody') }}
            </span>
        </p>

        <p
            v-for="card in unreachable"
            :key="card.account.chain"
            class="cw-note cw-note-bad"
            style="margin-bottom: 18px"
        >
            <span style="flex: 1">
                <strong style="display: block">{{
                    t('rpcErrorTitle', { chain: card.account.label })
                }}</strong>
                <span
                    style="
                        font: 400 11px/1.5 var(--cw-mono);
                        color: var(--cw-muted);
                    "
                    >{{ t('rpcErrorBody') }}</span
                >
            </span>
            <button
                type="button"
                class="cw-back"
                style="color: var(--cw-bad-soft)"
                @click="wallet.refreshBalances()"
            >
                {{ t('retry') }}
            </button>
            <button
                type="button"
                class="cw-note-close"
                :title="t('rpcErrorDismiss')"
                :aria-label="t('rpcErrorDismiss')"
                @click="dismissed.add(card.account.chain)"
            >
                ×
            </button>
        </p>

        <div class="cw-label">{{ t('totalPortfolio') }}</div>
        <div
            style="
                display: flex;
                align-items: baseline;
                gap: 10px;
                margin-top: 10px;
            "
        >
            <span
                class="cw-total"
                :style="
                    unaccounted > 0 ? { color: 'var(--cw-muted)' } : undefined
                "
                >{{ formatUsd(total, locale) }}</span
            >
        </div>
        <div
            style="
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: center;
                margin-top: 10px;
            "
        >
            <span
                v-if="unaccounted > 0"
                style="
                    font: 400 11px/1 var(--cw-mono);
                    color: var(--cw-pending);
                "
                >{{ t('pricePartial') }}</span
            >
            <span class="cw-label" style="color: var(--cw-faint)">{{
                unaccounted > 0
                    ? t('priceMissing', {
                          count: unaccounted,
                          total: cards.length,
                      })
                    : t('priceSource')
            }}</span>
        </div>

        <div style="display: flex; gap: 8px; margin: 22px 0 24px">
            <button
                type="button"
                class="cw-btn cw-btn-primary"
                style="height: 48px"
                @click="emit('send')"
            >
                {{ t('send') }}
            </button>
            <button
                type="button"
                class="cw-btn cw-btn-secondary"
                style="height: 48px"
                @click="emit('receive')"
            >
                {{ t('receive') }}
            </button>
        </div>

        <!--
          Three shortcuts, not a menu: the same holdings read as tokens, the
          same holdings read as shares, and the keys underneath both.
        -->
        <div style="display: flex; gap: 8px; margin-bottom: 24px">
            <button type="button" class="cw-tile" @click="emit('tokens')">
                <span style="font: 500 12px/1 var(--cw-sans)">{{
                    t('tokens')
                }}</span>
                <span class="cw-label" style="font-size: 9px">{{
                    t('tileTokensHint')
                }}</span>
            </button>
            <button type="button" class="cw-tile" @click="emit('analytics')">
                <span style="font: 500 12px/1 var(--cw-sans)">{{
                    t('navAnalytics')
                }}</span>
                <span class="cw-label" style="font-size: 9px">{{
                    t('tileAnalyticsHint')
                }}</span>
            </button>
            <button type="button" class="cw-tile" @click="emit('security')">
                <span style="font: 500 12px/1 var(--cw-sans)">{{
                    t('navSecurity')
                }}</span>
                <span class="cw-label" style="font-size: 9px">{{
                    t('tileSecurityHint')
                }}</span>
            </button>
        </div>

        <div class="cw-row" style="margin-bottom: 10px">
            <span class="cw-label">{{ t('networks') }}</span>
            <span class="cw-label" style="color: var(--cw-fainter)">{{
                t('derivedCount', { count: cards.length })
            }}</span>
        </div>

        <div class="cw-stack" style="gap: 8px">
            <template v-for="card in groupedCards" :key="card.account.chain">
                <div v-if="card.heading" class="cw-group">
                    <span
                        class="cw-label"
                        style="
                            font-size: 9px;
                            letter-spacing: 0.2em;
                            color: var(--cw-faint);
                        "
                        >{{ card.heading }}</span
                    >
                </div>
                <button
                    type="button"
                    class="cw-card cw-card-button"
                    :class="{ 'cw-card-custom': card.account.custom }"
                    @click="emit('open', card.account.chain)"
                >
                    <div style="display: flex; align-items: center; gap: 12px">
                        <NetworkMark :chain="card.account.chain" />
                        <span style="flex: 1; min-width: 0; text-align: left">
                            <span
                                style="
                                    display: block;
                                    font: 500 14px/1.2 var(--cw-sans);
                                    color: var(--cw-text);
                                "
                                >{{ card.account.label }}</span
                            >
                            <span
                                style="
                                    display: block;
                                    margin-top: 2px;
                                    font: 400 10px/1.4 var(--cw-mono);
                                    color: var(--cw-dim);
                                "
                                >{{ card.account.symbol
                                }}<template v-if="card.tokenCount > 0">
                                    ·
                                    {{
                                        t('tokenCount', {
                                            count: card.tokenCount,
                                        })
                                    }}</template
                                ></span
                            >
                        </span>
                        <span style="text-align: right">
                            <span
                                class="cw-num"
                                style="display: block"
                                :style="{
                                    color:
                                        card.amount === null
                                            ? 'var(--cw-dim)'
                                            : 'var(--cw-text)',
                                }"
                            >
                                {{ card.loading ? '…' : (card.amount ?? '—') }}
                            </span>
                            <span
                                style="
                                    display: block;
                                    margin-top: 2px;
                                    font: 400 11px/1.4 var(--cw-mono);
                                    color: var(--cw-dim);
                                "
                            >
                                {{
                                    card.readable
                                        ? card.usd === null
                                            ? t('unpriced')
                                            : formatUsd(card.usd, locale)
                                        : t('noBalanceHere')
                                }}
                            </span>
                        </span>
                    </div>
                    <!--
                      A network the user added carries its own provenance line:
                      the account is as real as any other, the endpoint it is
                      read through is the part nobody checked.
                    -->
                    <div
                        v-if="card.account.custom"
                        style="
                            margin-top: 11px;
                            padding-top: 10px;
                            border-top: 1px solid var(--cw-line);
                            font: 400 10px/1 var(--cw-mono);
                            letter-spacing: 0.08em;
                            color: var(--cw-meta);
                            text-transform: uppercase;
                        "
                    >
                        {{ t('addedByYou') }} · {{ t('endpointUnverified') }}
                    </div>
                </button>
            </template>

            <button
                type="button"
                class="cw-dashed"
                style="margin-top: 4px"
                @click="emit('addNetwork')"
            >
                <span
                    style="
                        display: flex;
                        width: 32px;
                        height: 32px;
                        flex: none;
                        align-items: center;
                        justify-content: center;
                        border: 1px dashed var(--cw-border);
                        font: 400 15px/1 var(--cw-mono);
                        color: var(--cw-muted);
                    "
                    >+</span
                >
                <span style="flex: 1">
                    <span
                        style="
                            display: block;
                            font: 500 13px/1.2 var(--cw-sans);
                        "
                        >{{ t('addNetwork') }}</span
                    >
                    <span
                        style="
                            display: block;
                            margin-top: 2px;
                            font: 400 10px/1.4 var(--cw-mono);
                            color: var(--cw-dim);
                        "
                        >{{ t('addNetworkHint') }}</span
                    >
                </span>
                <span
                    style="
                        font: 400 12px/1 var(--cw-mono);
                        color: var(--cw-dim);
                    "
                    >→</span
                >
            </button>
        </div>

        <div
            v-if="orphaned"
            style="
                margin-top: 28px;
                padding: 28px 20px;
                border: 1px dashed var(--cw-border-soft);
                text-align: center;
            "
        >
            <div class="cw-label" style="margin-bottom: 10px">
                {{ t('orphanTitle') }}
            </div>
            <p class="cw-prose" style="max-width: 40ch; margin: 0 auto 18px">
                {{ t('orphanBody') }}
            </p>
            <button type="button" class="cw-ghost" @click="emit('accounts')">
                {{ t('accounts') }}
            </button>
        </div>

        <div
            v-else-if="isEmpty"
            style="
                margin-top: 28px;
                padding: 28px 20px;
                border: 1px dashed var(--cw-border-soft);
                text-align: center;
            "
        >
            <div class="cw-label" style="margin-bottom: 10px">
                {{ t('emptyTitle') }}
            </div>
            <p class="cw-prose" style="max-width: 34ch; margin: 0 auto 18px">
                {{ t('emptyBody') }}
            </p>
            <button type="button" class="cw-ghost" @click="emit('receive')">
                {{ t('showAddress') }}
            </button>
        </div>

        <div v-else-if="recent.length > 0" style="margin-top: 26px">
            <div class="cw-label" style="margin-bottom: 10px">
                {{ t('recent') }}
            </div>
            <TxList
                :entries="recent"
                :locale="locale"
                :status-labels="statusLabels"
                :sent-to="t('sentTo')"
                :received-from="t('receivedFrom')"
            />
        </div>
    </div>
</template>
