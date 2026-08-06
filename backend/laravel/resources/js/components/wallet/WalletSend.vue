<script setup lang="ts">
import { CircleCheck, CircleX, Loader, RefreshCw } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import HoldButton from '@/components/wallet/HoldButton.vue';
import NetworkMark from '@/components/wallet/NetworkMark.vue';
import StatusPill from '@/components/wallet/StatusPill.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { formatUnits, parseUnits, walletChain } from '@/lib/wallet';
import type { WalletChainId, WalletFeeTier } from '@/lib/wallet';
import { formatUsd, shortAddress, usdValue } from '@/lib/wallet/format';
import { walletMessages } from '@/lib/walletMessages';

/**
 * Compose → review → sign → outcome.
 *
 * Two rules run this screen. Nothing is signed without one plain-language
 * sentence stating what the signature does, above the numbers rather than
 * instead of them. And the confirm control is a hold, not a tap, because the
 * step after it cannot be undone by anything this wallet can do.
 *
 * Every number here comes from the chain: the fee is a live quote, MAX is the
 * balance minus that quote, and the total debit is their sum. When the fee
 * cannot be read the screen refuses to build a transaction instead of guessing.
 */

const props = defineProps<{
    wallet: MultiWallet;
    chain: WalletChainId;
    prices: Record<string, number | null>;
}>();

const emit = defineEmits<{
    back: [];
    pick: [chain: WalletChainId];
    sent: [];
}>();

const { locale, t } = useLocale(walletMessages);

type Phase = 'compose' | 'review' | 'status';
type Outcome = 'signing' | 'pending' | 'confirmed' | 'failed';

const phase = ref<Phase>('compose');
const outcome = ref<Outcome>('signing');
const to = ref('');
const amount = ref('');
const tier = ref<WalletFeeTier>('normal');
const txHash = ref<string | null>(null);
const failure = ref<string | null>(null);

const chain = computed(() => walletChain(props.chain));

const account = computed(() =>
    props.wallet.accounts.value.find(
        (candidate) => candidate.chain === props.chain,
    ),
);

const balance = computed(
    () => props.wallet.balances.value[props.chain]?.value ?? null,
);

const quotes = computed(() => props.wallet.fees.value[props.chain] ?? []);

const fee = computed(
    () => quotes.value.find((quote) => quote.tier === tier.value)?.fee ?? null,
);

/** Typed amount in smallest units, or null while it is not a number yet. */
const amountUnits = computed(() => {
    if (amount.value.trim() === '') {
        return null;
    }

    try {
        return parseUnits(amount.value, chain.value.decimals);
    } catch {
        return null;
    }
});

const addressValid = computed(
    () =>
        to.value.trim().length > 0 &&
        chain.value.isValidAddress(to.value.trim()),
);

const shortfall = computed(() => {
    if (
        amountUnits.value === null ||
        fee.value === null ||
        balance.value === null
    ) {
        return null;
    }

    const needed = amountUnits.value + fee.value;

    return needed > balance.value ? needed - balance.value : null;
});

const total = computed(() =>
    amountUnits.value === null || fee.value === null
        ? null
        : amountUnits.value + fee.value,
);

const canReview = computed(
    () =>
        addressValid.value &&
        amountUnits.value !== null &&
        amountUnits.value > 0n &&
        fee.value !== null &&
        shortfall.value === null,
);

const setMax = (): void => {
    if (balance.value === null || fee.value === null) {
        return;
    }

    const spendable = balance.value - fee.value;
    amount.value =
        spendable > 0n ? formatUnits(spendable, chain.value.decimals, 12) : '0';
};

const pasteTo = async (): Promise<void> => {
    to.value = (await navigator.clipboard.readText()).trim();
};

/** The one sentence shown before every signature. */
const sentence = computed(() =>
    t('signSentence', {
        amount:
            amountUnits.value === null
                ? '0'
                : formatUnits(amountUnits.value, chain.value.decimals, 8),
        symbol: chain.value.symbol,
        chain: chain.value.label,
        to: to.value.trim() ? shortAddress(to.value.trim()) : '—',
        network: chain.value.label,
        fee:
            fee.value === null
                ? '—'
                : formatUnits(fee.value, chain.value.decimals, 8),
    }),
);

const OUTCOMES: Record<
    Outcome,
    {
        icon: typeof Loader;
        status: 'signing' | 'pending' | 'confirmed' | 'failed';
    }
> = {
    signing: { icon: Loader, status: 'signing' },
    pending: { icon: Loader, status: 'pending' },
    confirmed: { icon: CircleCheck, status: 'confirmed' },
    failed: { icon: CircleX, status: 'failed' },
};

const sign = async (): Promise<void> => {
    phase.value = 'status';
    outcome.value = 'signing';
    failure.value = null;
    txHash.value = null;

    try {
        txHash.value = await props.wallet.send(
            props.chain,
            to.value.trim(),
            amount.value,
            tier.value,
        );
        outcome.value = 'pending';
    } catch (error) {
        outcome.value = 'failed';
        failure.value = error instanceof Error ? error.message : String(error);

        return;
    }

    void props.wallet.refreshBalances();
    emit('sent');

    // Broadcast is not settlement. Watching can time out without the transfer
    // failing, so a timeout leaves the row pending rather than calling it dead.
    if (!chain.value.awaitOutcome) {
        return;
    }

    try {
        outcome.value = await chain.value.awaitOutcome(txHash.value);
    } catch (error) {
        failure.value = error instanceof Error ? error.message : String(error);
    }

    void props.wallet.refreshBalances();
    void props.wallet.refreshHistory(props.chain);
};

const reset = (): void => {
    phase.value = 'compose';
    txHash.value = null;
    failure.value = null;
};

const loadFees = (): void => {
    void props.wallet.refreshFees(props.chain);
};

onMounted(loadFees);
watch(
    () => props.chain,
    () => {
        to.value = '';
        amount.value = '';
        loadFees();
    },
);
</script>

<template>
    <div v-if="account" class="cw-stack cw-screen">
        <!-- Compose -->
        <template v-if="phase !== 'status'">
            <div class="cw-row" style="margin-bottom: 20px">
                <button type="button" class="cw-back" @click="emit('back')">
                    ← {{ t('back') }}
                </button>
                <span style="font: 500 12px/1 var(--cw-sans)">{{
                    t('send')
                }}</span>
                <span style="width: 44px"></span>
            </div>

            <div class="cw-seg" style="margin-bottom: 20px">
                <button
                    v-for="candidate in wallet.accounts.value"
                    :key="candidate.chain"
                    type="button"
                    class="cw-seg-item"
                    :aria-pressed="candidate.chain === chain.id"
                    @click="emit('pick', candidate.chain)"
                >
                    {{ candidate.label }}
                    <span
                        class="cw-seg-bar"
                        :style="{
                            background:
                                candidate.chain === chain.id
                                    ? `var(--cw-net-${candidate.chain})`
                                    : 'transparent',
                        }"
                    />
                </button>
            </div>

            <p v-if="!account.capabilities.send" class="cw-note cw-note-warn">
                <span>{{
                    t('sendUnsupported', { chain: account.label })
                }}</span>
            </p>

            <template v-else>
                <div class="cw-label" style="margin-bottom: 8px">
                    {{ t('recipient') }}
                </div>
                <div style="display: flex; gap: 8px">
                    <input
                        v-model="to"
                        class="cw-input"
                        type="text"
                        autocomplete="off"
                        spellcheck="false"
                        :aria-label="t('recipient')"
                        :aria-invalid="to.length > 0 && !addressValid"
                    />
                    <button
                        type="button"
                        class="cw-icon-btn"
                        style="height: 48px; width: 48px"
                        :aria-label="t('paste')"
                        @click="pasteTo"
                    >
                        ⎘
                    </button>
                </div>
                <p
                    v-if="to.trim().length > 0"
                    style="margin: 8px 0 0; font: 400 11px/1.4 var(--cw-mono)"
                    :style="{
                        color: addressValid
                            ? 'var(--cw-ok)'
                            : 'var(--cw-bad-soft)',
                    }"
                >
                    {{
                        addressValid
                            ? `✓ ${t('addressValid', { kind: account.label })}`
                            : t('addressInvalid', { kind: account.label })
                    }}
                </p>

                <div class="cw-label" style="margin: 22px 0 8px">
                    {{ t('amount') }}
                </div>
                <div
                    class="cw-card"
                    style="padding: 14px; border-radius: 4px"
                    :style="
                        shortfall !== null
                            ? { borderColor: 'var(--cw-bad)' }
                            : { borderColor: 'var(--cw-border-soft)' }
                    "
                >
                    <div style="display: flex; align-items: center; gap: 12px">
                        <input
                            v-model="amount"
                            type="text"
                            inputmode="decimal"
                            placeholder="0.00"
                            :aria-label="t('amount')"
                            style="
                                flex: 1;
                                min-width: 0;
                                border: none;
                                background: transparent;
                                font: 500 26px/1 var(--cw-mono);
                                color: var(--cw-text);
                                outline: none;
                                padding: 0;
                            "
                        />
                        <span
                            style="
                                font: 400 14px/1 var(--cw-mono);
                                color: var(--cw-muted);
                            "
                            >{{ account.symbol }}</span
                        >
                        <button
                            type="button"
                            class="cw-ghost"
                            style="
                                min-height: 32px;
                                border-color: var(--cw-accent);
                                color: var(--cw-accent);
                            "
                            :disabled="balance === null || fee === null"
                            @click="setMax"
                        >
                            {{ t('max') }}
                        </button>
                    </div>
                    <div
                        class="cw-row"
                        style="
                            margin-top: 12px;
                            padding-top: 12px;
                            border-top: 1px solid var(--cw-line);
                        "
                    >
                        <span
                            style="
                                font: 400 11px/1 var(--cw-mono);
                                color: var(--cw-dim);
                            "
                            >{{
                                formatUsd(
                                    usdValue(
                                        amountUnits,
                                        account.decimals,
                                        prices[chain.id] ?? null,
                                    ),
                                    locale,
                                )
                            }}</span
                        >
                        <span
                            style="
                                font: 400 11px/1 var(--cw-mono);
                                color: var(--cw-dim);
                            "
                            >{{ t('balanceShort') }}
                            {{
                                balance === null
                                    ? '—'
                                    : formatUnits(balance, account.decimals, 6)
                            }}</span
                        >
                    </div>
                </div>

                <p
                    v-if="shortfall !== null"
                    class="cw-note cw-note-bad"
                    style="margin-top: 10px"
                >
                    <span>
                        <strong style="display: block">{{
                            t('insufficientTitle')
                        }}</strong>
                        {{
                            t('insufficientBody', {
                                amount: formatUnits(
                                    shortfall,
                                    account.decimals,
                                    8,
                                ),
                                symbol: account.symbol,
                            })
                        }}
                    </span>
                </p>

                <div class="cw-label" style="margin: 22px 0 8px">
                    {{ t('networkFee') }}
                </div>
                <p v-if="quotes.length === 0" class="cw-note cw-note-warn">
                    <span>{{ t('feeUnavailable') }}</span>
                    <button
                        type="button"
                        class="cw-back"
                        style="color: inherit"
                        :aria-label="t('retry')"
                        @click="loadFees"
                    >
                        <RefreshCw :size="14" aria-hidden="true" />
                    </button>
                </p>
                <div v-else style="display: flex; gap: 8px">
                    <button
                        v-for="quote in quotes"
                        :key="quote.tier"
                        type="button"
                        class="cw-stack"
                        style="
                            flex: 1;
                            min-height: 60px;
                            gap: 5px;
                            padding: 10px;
                            border-radius: 4px;
                            text-align: left;
                            cursor: pointer;
                        "
                        :style="{
                            border: `1px solid ${
                                quote.tier === tier
                                    ? `var(--cw-net-${chain.id})`
                                    : 'var(--cw-border-soft)'
                            }`,
                            background:
                                quote.tier === tier
                                    ? 'rgba(47,233,224,.06)'
                                    : 'var(--cw-surface)',
                        }"
                        :aria-pressed="quote.tier === tier"
                        @click="tier = quote.tier"
                    >
                        <span
                            style="
                                font: 500 11px/1 var(--cw-mono);
                                letter-spacing: 0.1em;
                            "
                            :style="{
                                color:
                                    quote.tier === tier
                                        ? `var(--cw-net-${chain.id})`
                                        : 'var(--cw-muted)',
                            }"
                            >{{
                                quote.tier === 'slow'
                                    ? t('feeSlow')
                                    : quote.tier === 'normal'
                                      ? t('feeNormal')
                                      : t('feeFast')
                            }}</span
                        >
                        <span
                            style="
                                font: 400 10px/1.3 var(--cw-mono);
                                color: var(--cw-dim);
                            "
                            >{{
                                formatUnits(quote.fee, account.decimals, 8)
                            }}</span
                        >
                        <span
                            style="
                                font: 400 10px/1.3 var(--cw-mono);
                                color: var(--cw-fainter);
                            "
                            >{{ quote.basis }}</span
                        >
                    </button>
                </div>

                <div
                    style="
                        margin-top: 22px;
                        padding: 14px 16px;
                        border: 1px solid #1b2126;
                        background: var(--cw-surface);
                    "
                >
                    <div
                        class="cw-label"
                        style="margin-bottom: 9px; color: var(--cw-meta)"
                    >
                        {{ t('youWillSign') }}
                    </div>
                    <p
                        style="
                            margin: 0;
                            font: 400 13px/1.65 var(--cw-sans);
                            color: var(--cw-body);
                            text-wrap: pretty;
                        "
                    >
                        {{ sentence }}
                    </p>
                </div>

                <div class="cw-fill" style="min-height: 20px"></div>
                <button
                    type="button"
                    class="cw-btn cw-btn-primary"
                    style="margin-top: 18px"
                    :disabled="!canReview"
                    @click="phase = 'review'"
                >
                    {{ t('reviewTransaction') }}
                </button>
            </template>
        </template>

        <!-- Outcome -->
        <template v-else>
            <div style="display: flex; justify-content: flex-end">
                <button
                    type="button"
                    class="cw-icon-btn"
                    style="border: none"
                    :aria-label="t('backToPortfolio')"
                    @click="emit('back')"
                >
                    ✕
                </button>
            </div>
            <div
                class="cw-stack"
                style="
                    flex: 1;
                    align-items: center;
                    justify-content: center;
                    text-align: center;
                    padding-bottom: 40px;
                "
            >
                <component
                    :is="OUTCOMES[outcome].icon"
                    :size="44"
                    aria-hidden="true"
                    :style="{
                        color:
                            outcome === 'confirmed'
                                ? 'var(--cw-ok)'
                                : outcome === 'failed'
                                  ? 'var(--cw-bad)'
                                  : outcome === 'pending'
                                    ? 'var(--cw-pending)'
                                    : 'var(--cw-text)',
                        marginBottom: '26px',
                        animation:
                            outcome === 'signing' || outcome === 'pending'
                                ? 'cw-pulse 1.4s infinite'
                                : undefined,
                    }"
                />
                <StatusPill
                    :status="OUTCOMES[outcome].status"
                    :label="
                        outcome === 'signing'
                            ? t('txSigningLabel')
                            : outcome === 'pending'
                              ? t('txPendingLabel')
                              : outcome === 'confirmed'
                                ? t('txConfirmedLabel')
                                : t('txFailedLabel')
                    "
                />
                <h3
                    class="cw-title"
                    style="margin: 12px 0 10px; font-size: 22px"
                >
                    {{
                        outcome === 'signing'
                            ? t('txSigningTitle')
                            : outcome === 'pending'
                              ? t('txPendingTitle')
                              : outcome === 'confirmed'
                                ? t('txConfirmedTitle')
                                : t('txFailedTitle')
                    }}
                </h3>
                <p class="cw-prose" style="max-width: 34ch">
                    {{
                        outcome === 'signing'
                            ? t('txSigningBody')
                            : outcome === 'pending'
                              ? t('txPendingBody')
                              : outcome === 'confirmed'
                                ? t('txConfirmedBody')
                                : t('txFailedBody')
                    }}
                </p>

                <div
                    class="cw-card"
                    style="width: 100%; margin-top: 28px; padding: 0"
                >
                    <div class="cw-kv">
                        <span class="cw-kv-key">{{ t('kAmount') }}</span>
                        <span class="cw-kv-val"
                            >{{ amount }} {{ account.symbol }}</span
                        >
                    </div>
                    <div class="cw-kv">
                        <span class="cw-kv-key">{{ t('kTo') }}</span>
                        <span class="cw-kv-val">{{
                            shortAddress(to.trim())
                        }}</span>
                    </div>
                    <!--
                      A broadcast transaction keeps its hash on screen even
                      when watching it fails: the hash is how the transfer is
                      found again, and losing sight of it is what turns a slow
                      confirmation into a second payment.
                    -->
                    <div v-if="txHash" class="cw-kv">
                        <span class="cw-kv-key">{{ t('kTxHash') }}</span>
                        <span class="cw-kv-val">{{
                            shortAddress(txHash, 8, 6)
                        }}</span>
                    </div>
                    <div v-if="failure" class="cw-kv">
                        <span class="cw-kv-key">{{ t('kReason') }}</span>
                        <span
                            class="cw-kv-val"
                            style="color: var(--cw-bad-soft)"
                            >{{ failure }}</span
                        >
                    </div>
                </div>
            </div>

            <div class="cw-stack" style="gap: 8px">
                <button
                    v-if="outcome === 'failed'"
                    type="button"
                    class="cw-btn cw-btn-primary"
                    @click="reset"
                >
                    {{ t('adjustRetry') }}
                </button>
                <button
                    type="button"
                    class="cw-btn cw-btn-secondary"
                    @click="emit('back')"
                >
                    {{ t('backToPortfolio') }}
                </button>
                <a
                    v-if="txHash && chain.explorerTxUrl(txHash)"
                    :href="chain.explorerTxUrl(txHash) ?? undefined"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="cw-ghost"
                    style="width: 100%; text-decoration: none"
                >
                    {{ t('viewInExplorer') }} ↗
                </a>
            </div>
        </template>

        <!-- Review sheet -->
        <div v-if="phase === 'review'" class="cw-sheet">
            <div class="cw-sheet-panel">
                <div
                    style="
                        width: 36px;
                        height: 3px;
                        margin: 0 auto 20px;
                        border-radius: 2px;
                        background: var(--cw-border);
                    "
                ></div>
                <div class="cw-row" style="margin-bottom: 6px">
                    <h3
                        class="cw-title"
                        style="font-size: 19px; line-height: 1.2"
                    >
                        {{ t('confirmTransaction') }}
                    </h3>
                    <button
                        type="button"
                        class="cw-icon-btn"
                        style="border: none"
                        :aria-label="t('cancel')"
                        @click="phase = 'compose'"
                    >
                        ✕
                    </button>
                </div>
                <p
                    class="cw-prose"
                    style="margin-bottom: 18px; font-size: 12px"
                >
                    {{ t('reviewBody') }}
                </p>

                <div style="border: 1px solid var(--cw-hairline)">
                    <div class="cw-kv">
                        <span class="cw-kv-key">{{ t('kNetwork') }}</span>
                        <span
                            class="cw-kv-val"
                            style="display: flex; align-items: center; gap: 8px"
                        >
                            <NetworkMark :chain="chain.id" dot :size="6" />
                            {{ account.label }}
                        </span>
                    </div>
                    <div class="cw-kv">
                        <span class="cw-kv-key">{{ t('kTo') }}</span>
                        <span
                            class="cw-kv-val"
                            style="max-width: 240px; font-weight: 400"
                            >{{ to.trim() }}</span
                        >
                    </div>
                    <div class="cw-kv">
                        <span class="cw-kv-key">{{ t('kAmount') }}</span>
                        <span class="cw-kv-val" style="font-size: 14px"
                            >{{ amount }} {{ account.symbol }}</span
                        >
                    </div>
                    <div class="cw-kv">
                        <span class="cw-kv-key">{{ t('kFee') }}</span>
                        <span class="cw-kv-val" style="font-weight: 400">{{
                            fee === null
                                ? '—'
                                : formatUnits(fee, account.decimals, 8)
                        }}</span>
                    </div>
                    <div
                        class="cw-kv"
                        style="align-items: center; background: #0c0f13"
                    >
                        <span class="cw-kv-key" style="color: var(--cw-text)">{{
                            t('kTotal')
                        }}</span>
                        <span style="text-align: right">
                            <span
                                style="
                                    display: block;
                                    font: 600 16px/1 var(--cw-mono);
                                    color: var(--cw-accent);
                                "
                                >{{
                                    total === null
                                        ? '—'
                                        : formatUnits(
                                              total,
                                              account.decimals,
                                              8,
                                          )
                                }}
                                {{ account.symbol }}</span
                            >
                            <span
                                style="
                                    display: block;
                                    margin-top: 4px;
                                    font: 400 11px/1 var(--cw-mono);
                                    color: var(--cw-dim);
                                "
                                >{{
                                    formatUsd(
                                        usdValue(
                                            total,
                                            account.decimals,
                                            prices[chain.id] ?? null,
                                        ),
                                        locale,
                                    )
                                }}</span
                            >
                        </span>
                    </div>
                </div>

                <div
                    style="
                        margin-top: 14px;
                        padding: 13px 14px;
                        border: 1px solid #1b2126;
                        background: #080a0c;
                    "
                >
                    <div
                        class="cw-label"
                        style="margin-bottom: 8px; color: var(--cw-meta)"
                    >
                        {{ t('plainLanguage') }}
                    </div>
                    <p
                        style="
                            margin: 0;
                            font: 400 12px/1.6 var(--cw-sans);
                            color: #b6bec6;
                        "
                    >
                        {{ sentence }} {{ t('nothingElse') }}
                    </p>
                </div>

                <div style="margin-top: 18px">
                    <HoldButton
                        :label="t('holdToSign')"
                        :disabled="wallet.busy.value"
                        @complete="sign"
                    />
                </div>
                <button
                    type="button"
                    class="cw-ghost"
                    style="width: 100%; margin-top: 8px; border: none"
                    @click="phase = 'compose'"
                >
                    {{ t('cancel') }}
                </button>
            </div>
        </div>
    </div>
</template>
