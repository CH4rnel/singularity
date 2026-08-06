<script setup lang="ts">
import { computed } from 'vue';
import NetworkMark from '@/components/wallet/NetworkMark.vue';
import TxList from '@/components/wallet/TxList.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { formatUnits } from '@/lib/wallet';
import type { WalletChainId, WalletTxStatus } from '@/lib/wallet';
import { formatUsd, usdValue } from '@/lib/wallet/format';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The home screen: one total, three networks, recent movement.
 *
 * Every failure a portfolio can have is a designed state here rather than a
 * toast — an unreachable node, a coin with no price, an empty vault. A number
 * that could not be read is never rendered as zero, because zero is a claim
 * about the balance and "—" is a claim about the connection.
 */

const props = defineProps<{
    wallet: MultiWallet;
    prices: Record<string, number | null>;
    online: boolean;
}>();

const emit = defineEmits<{
    open: [chain: WalletChainId];
    send: [];
    receive: [];
}>();

const { locale, t } = useLocale(walletMessages);

const statusLabels = computed<Record<WalletTxStatus, string>>(() => ({
    confirmed: t('statusConfirmed'),
    pending: t('statusPending'),
    failed: t('statusFailed'),
}));

const cards = computed(() =>
    props.wallet.accounts.value.map((account) => {
        const balance = props.wallet.balances.value[account.chain];
        const price = props.prices[account.chain] ?? null;

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
        };
    }),
);

/** Networks whose balance could not be read at all. */
const unreachable = computed(() =>
    cards.value.filter((card) => card.readable && card.error !== null),
);

/** Networks holding value the total cannot include — no price, or no read. */
const unaccounted = computed(
    () => cards.value.filter((card) => card.usd === null).length,
);

const total = computed(() =>
    cards.value.reduce((sum, card) => sum + (card.usd ?? 0), 0),
);

const isEmpty = computed(() =>
    cards.value.every(
        (card) =>
            !card.readable ||
            (card.error === null && !card.loading && card.amount === '0'),
    ),
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

        <div class="cw-row" style="margin-bottom: 10px">
            <span class="cw-label">{{ t('networks') }}</span>
            <span class="cw-label" style="color: var(--cw-fainter)">{{
                t('derivedCount', { count: cards.length })
            }}</span>
        </div>

        <div class="cw-stack" style="gap: 8px">
            <button
                v-for="card in cards"
                :key="card.account.chain"
                type="button"
                class="cw-card cw-card-button"
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
                            >{{ card.account.symbol }}</span
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
            </button>
        </div>

        <div
            v-if="isEmpty"
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
