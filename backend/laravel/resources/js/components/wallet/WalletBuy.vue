<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NetworkMark from '@/components/wallet/NetworkMark.vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { shippedChains, walletChain } from '@/lib/wallet';
import { shortAddress } from '@/lib/wallet/format';
import {
    countryFromLanguage,
    fetchOnrampCatalogue,
    fiatForCountry,
    mirIsTheQuestion,
    needsSecondStep,
    offerAdvantagePct,
    parseFiatAmount,
    quoteOnramp,
    readOnrampOrder,
    readRememberedOnramp,
    rememberOnrampOrder,
    startOnrampCheckout,
} from '@/lib/wallet/onramp';
import type {
    OnrampCatalogue,
    OnrampDelivery,
    OnrampMethod,
    OnrampOffer,
    OnrampOrder,
} from '@/lib/wallet/onramp';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The door for somebody who holds a card and nothing else.
 *
 * Everything else in this wallet assumes coins already exist: the bridge moves
 * them, the swap trades them, the gas station drips enough to move them. This
 * screen is where the first ones come from, and the three things it must never
 * blur are on it in plain words.
 *
 * **We are not the seller.** A provider takes the card, runs its own KYC and
 * settles the crypto; Cyberia sees no card, holds no money and takes no
 * custody. That sentence sits above the press that leaves for their page.
 *
 * **It does not arrive on Cyberia.** No on-ramp settles to this chain, so a
 * purchase lands on one that they do serve, at the same address this wallet
 * derived, and reaching Cyberia is a second act — the cross-chain swap this
 * wallet already has. Naming it beats a single button that silently becomes
 * two.
 *
 * **Мир is not served.** Not by us and not by any of them: those rails end at
 * the borders of the countries wired into them and every licensed provider
 * refuses the network. The row stays on the screen with the reason, because a
 * missing option reads as an oversight to the people who hold that card.
 */

const props = defineProps<{ wallet: MultiWallet }>();

const emit = defineEmits<{
    back: [];
    /** Money has landed elsewhere and wants moving onto Cyberia. */
    crosschain: [];
}>();

const { t } = useLocale(walletMessages);

const METHODS: OnrampMethod[] = ['card', 'bank', 'apple_pay', 'google_pay'];

const catalogue = ref<OnrampCatalogue | null>(null);
const loading = ref(true);
const problem = ref<string | null>(null);

const country = ref<string | null>(null);
const fiat = ref('USD');
const amount = ref('100');
const method = ref<OnrampMethod>('card');
const delivery = ref('');

const offers = ref<OnrampOffer[]>([]);
const quoting = ref(false);
const starting = ref<string | null>(null);

const order = ref<OnrampOrder | null>(null);
let poll: ReturnType<typeof setInterval> | null = null;
let debounce: ReturnType<typeof setTimeout> | null = null;

/**
 * The currencies somebody can be quoted in.
 *
 * Every provider that can serve, and — when none can yet — every provider
 * that is listed at all. Before the first partner account exists nobody is
 * available, and narrowing to the available ones drew an *empty* currency
 * picker on a screen whose whole job is to explain what it would cost.
 */
const currencies = computed(() => {
    const providers = catalogue.value?.providers ?? [];
    const usable = providers.filter((provider) => provider.available);
    const seen = new Set<string>();

    (usable.length > 0 ? usable : providers).forEach((provider) =>
        provider.fiat.forEach((code) => seen.add(code)),
    );

    return [...seen].sort();
});

const row = computed<OnrampDelivery | null>(
    () =>
        catalogue.value?.delivery.find((one) => one.key === delivery.value) ??
        null,
);

/**
 * Whether the network a purchase would land on is switched on here.
 *
 * It matters for two reasons and the second is the important one: the address
 * is derived per network, so an off network has none to deliver to — and money
 * arriving on a network the portfolio does not draw is money somebody thinks
 * they lost.
 */
const chainOn = computed(
    () =>
        row.value !== null &&
        props.wallet.accounts.value.some(
            (account) => account.chain === row.value?.chain,
        ),
);

const address = computed(
    () =>
        props.wallet.accounts.value.find(
            (account) => account.chain === row.value?.chain,
        )?.address ?? null,
);

const amountOk = computed(() => parseFiatAmount(amount.value) !== null);

const mirFirst = computed(() => mirIsTheQuestion(country.value, fiat.value));

const advantage = computed(() => offerAdvantagePct(offers.value));

/** A provider's refusal, said in the reader's language. */
const reasonOf = (reason: string | null): string =>
    reason === null ? '' : t(`buyReason_${reason}`);

/**
 * A network's name and mark, whether or not it is switched on here.
 *
 * `walletChain()` answers only for registered networks, and the whole point of
 * this screen is that a purchase can land on one this wallet has switched off
 * — which drew every delivery row as "?? · base". The shipped list is the
 * registry before the switches, so it answers for all of them.
 */
const shipped = (id: string) =>
    shippedChains().find((entry) => entry.id === id) ?? null;

const chainLabel = (id: string): string => {
    if (shipped(id) !== null) {
        return shipped(id)!.label;
    }

    try {
        return walletChain(id as never).label;
    } catch {
        return id;
    }
};

const chainMark = (id: string) => shipped(id)?.mark;

const load = async (): Promise<void> => {
    loading.value = true;
    problem.value = null;

    try {
        const guess = countryFromLanguage(navigator.language ?? '');

        country.value = guess;
        fiat.value = fiatForCountry(guess);

        const answer = await fetchOnrampCatalogue(guess);

        catalogue.value = answer;
        delivery.value =
            answer.delivery.find((one) => one.key === answer.defaultDelivery)
                ?.key ??
            answer.delivery[0]?.key ??
            '';

        // A currency nobody quotes is worse than a wrong-looking default: it
        // answers every provider with `fiat_unsupported`.
        if (
            currencies.value.length > 0 &&
            !currencies.value.includes(fiat.value)
        ) {
            fiat.value = currencies.value.includes('USD')
                ? 'USD'
                : currencies.value[0];
        }
    } catch (error) {
        problem.value = error instanceof Error ? error.message : String(error);
    } finally {
        loading.value = false;
    }
};

const quote = async (): Promise<void> => {
    const size = parseFiatAmount(amount.value);

    if (size === null || row.value === null || address.value === null) {
        offers.value = [];

        return;
    }

    quoting.value = true;

    try {
        offers.value = await quoteOnramp({
            fiat: fiat.value,
            amount: size,
            country: country.value,
            method: method.value,
            delivery: delivery.value,
            address: address.value,
        });
    } catch (error) {
        problem.value = error instanceof Error ? error.message : String(error);
        offers.value = [];
    } finally {
        quoting.value = false;
    }
};

/**
 * Leave for the provider, in a tab of its own.
 *
 * A new tab rather than this one: the wallet is holding an unlocked vault and
 * navigating away from it to somebody else's checkout would lock it behind the
 * person's back, and coming back would be a fresh page rather than the order
 * they just started.
 */
const buy = async (provider: string): Promise<void> => {
    const size = parseFiatAmount(amount.value);

    if (size === null || address.value === null || starting.value !== null) {
        return;
    }

    starting.value = provider;
    problem.value = null;

    try {
        const started = await startOnrampCheckout({
            provider,
            fiat: fiat.value,
            amount: size,
            country: country.value,
            method: method.value,
            delivery: delivery.value,
            address: address.value,
        });

        rememberOnrampOrder(started.reference);
        order.value = started.order;
        window.open(started.url, '_blank', 'noopener,noreferrer');
        watchOrder();
    } catch (error) {
        problem.value = error instanceof Error ? error.message : String(error);
    } finally {
        starting.value = null;
    }
};

/**
 * Follow an order that is happening somewhere else.
 *
 * Only worth doing where the provider's webhook can be verified; where it
 * cannot, the row says so and the honest answer is that the coins will simply
 * appear in the wallet, which is the thing the person is watching anyway.
 */
const watchOrder = (): void => {
    if (poll !== null) {
        clearInterval(poll);
    }

    poll = setInterval(async () => {
        const reference = order.value?.reference;

        if (reference === undefined) {
            return;
        }

        try {
            const fresh = await readOnrampOrder(reference);

            order.value = fresh;

            if (fresh.status === 'delivered' || fresh.status === 'failed') {
                clearInterval(poll as ReturnType<typeof setInterval>);
                poll = null;
                void props.wallet.refreshBalances();
            }
        } catch {
            // A status that cannot be read is not a status that changed.
        }
    }, 8000);
};

/** An order this browser started, named in the address it came back on. */
const resume = async (): Promise<void> => {
    const url = new URL(window.location.href);
    const reference =
        url.searchParams.get('ref') ?? readRememberedOnramp()[0] ?? null;

    if (reference === null) {
        return;
    }

    try {
        order.value = await readOnrampOrder(reference);

        if (
            order.value.status !== 'delivered' &&
            order.value.status !== 'failed'
        ) {
            watchOrder();
        }
    } catch {
        // A reference from another device, or one this host has forgotten.
    }
};

watch([amount, fiat, method, delivery, chainOn], () => {
    if (debounce !== null) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(() => void quote(), 400);
});

onMounted(async () => {
    await load();
    await resume();
    void quote();
});

onBeforeUnmount(() => {
    if (poll !== null) {
        clearInterval(poll);
    }

    if (debounce !== null) {
        clearTimeout(debounce);
    }
});
</script>

<template>
    <div class="cw-stack">
        <button type="button" class="cw-back" @click="emit('back')">
            ← {{ t('wallet') }}
        </button>

        <h2 class="cw-title" style="margin: 22px 0 8px">{{ t('buyTitle') }}</h2>
        <p class="cw-prose">{{ t('buyBody') }}</p>

        <p v-if="problem" class="cw-note cw-note-bad" style="margin-top: 16px">
            <span>{{ problem }}</span>
        </p>

        <p
            v-if="!loading && catalogue && !catalogue.enabled"
            class="cw-note"
            style="margin-top: 18px"
        >
            <span>{{ t('buyOff') }}</span>
        </p>

        <!--
          An order already in flight. It is drawn above the form: somebody who
          came back from a provider's page is here to see what happened, not to
          start a second purchase.
        -->
        <div v-if="order" class="cw-card" style="margin-top: 20px">
            <div class="cw-row">
                <span class="cw-label">{{ t('buyOrder') }}</span>
                <span class="cw-data" style="color: var(--cw-text)">{{
                    t(`buyStatus_${order.status}`)
                }}</span>
            </div>
            <div class="cw-rowdata" style="margin-top: 10px">
                {{ order.fiatAmount }} {{ order.fiat }} · {{ order.asset }} ·
                {{ chainLabel(order.chain) }} ·
                {{ shortAddress(order.address) }}
            </div>
            <p
                v-if="order.status === 'delivered'"
                class="cw-prose"
                style="margin-top: 12px"
            >
                {{ t('buyDelivered') }}
            </p>
            <button
                v-if="
                    order.status === 'delivered' && needsSecondStep(order.chain)
                "
                type="button"
                class="cw-btn cw-btn-secondary"
                style="margin-top: 12px"
                @click="emit('crosschain')"
            >
                {{ t('buyMoveOn') }}
            </button>
        </div>

        <template v-if="!loading && catalogue?.enabled">
            <!--
              Мир, either as the first thing on the screen or as a row further
              down — but on the screen either way, with the reason.
            -->
            <div
                v-if="mirFirst"
                class="cw-card"
                style="margin-top: 20px; border-color: var(--cw-line)"
            >
                <div class="cw-row">
                    <span class="cw-label">{{ t('buyMir') }}</span>
                    <span class="cw-data" style="color: var(--cw-muted)">{{
                        catalogue.mir.available ? catalogue.mir.operator : '—'
                    }}</span>
                </div>
                <p class="cw-prose" style="margin-top: 10px">
                    {{
                        catalogue.mir.available
                            ? t('buyMirExternal')
                            : t('buyReason_mir_unserved')
                    }}
                </p>
                <a
                    v-if="catalogue.mir.available"
                    class="cw-btn cw-btn-secondary"
                    style="margin-top: 12px"
                    :href="catalogue.mir.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    >{{ t('buyMirOpen') }}</a
                >
            </div>

            <!-- What is being spent. -->
            <div class="cw-label" style="margin-top: 26px">
                {{ t('buySpend') }}
            </div>
            <div class="cw-card" style="margin-top: 10px">
                <div style="display: flex; gap: 10px">
                    <input
                        v-model="amount"
                        class="cw-input"
                        inputmode="decimal"
                        style="flex: 1"
                        :aria-label="t('buySpend')"
                    />
                    <select
                        v-model="fiat"
                        class="cw-input"
                        style="width: 110px"
                        :aria-label="t('buyCurrency')"
                    >
                        <option
                            v-for="code in currencies"
                            :key="code"
                            :value="code"
                        >
                            {{ code }}
                        </option>
                    </select>
                </div>
                <div class="cw-seg" style="margin-top: 12px">
                    <button
                        v-for="preset in ['50', '100', '250']"
                        :key="preset"
                        type="button"
                        class="cw-seg-item"
                        :aria-pressed="amount === preset"
                        @click="amount = preset"
                    >
                        {{ preset }}
                    </button>
                </div>
            </div>

            <!-- What arrives, and where. -->
            <div class="cw-label" style="margin-top: 22px">
                {{ t('buyGet') }}
            </div>
            <div class="cw-card" style="margin-top: 10px">
                <div class="cw-stack" style="gap: 8px">
                    <button
                        v-for="option in catalogue.delivery"
                        :key="option.key"
                        type="button"
                        class="cw-line-row"
                        :aria-pressed="delivery === option.key"
                        @click="delivery = option.key"
                    >
                        <NetworkMark
                            :chain="option.chain as never"
                            :mark="chainMark(option.chain)"
                            :size="18"
                        />
                        <span style="flex: 1; text-align: left">
                            {{ option.asset }}
                            <span class="cw-rowdata"
                                >· {{ chainLabel(option.chain) }}</span
                            >
                        </span>
                        <span v-if="delivery === option.key" class="cw-data"
                            >✓</span
                        >
                    </button>
                </div>

                <p class="cw-prose" style="margin-top: 12px">
                    {{ t('buyNotCyberia') }}
                </p>

                <!--
                  A network that is off has no address to deliver to, and money
                  landing on a network the portfolio does not draw is money
                  somebody believes they lost.
                -->
                <template v-if="row && !chainOn">
                    <p class="cw-note cw-note-warn" style="margin-top: 12px">
                        <span>{{
                            t('buyNetworkOff', { chain: chainLabel(row.chain) })
                        }}</span>
                    </p>
                    <button
                        type="button"
                        class="cw-btn cw-btn-secondary"
                        style="margin-top: 10px"
                        @click="
                            props.wallet.setNetwork(row.chain as never, true)
                        "
                    >
                        {{ t('buyNetworkOn') }}
                    </button>
                </template>
                <div
                    v-else-if="address"
                    class="cw-rowdata"
                    style="margin-top: 12px"
                >
                    {{ t('buyTo') }} {{ shortAddress(address) }}
                </div>
            </div>

            <!-- How it is paid for. -->
            <div class="cw-seg" style="margin-top: 22px">
                <button
                    v-for="one in METHODS"
                    :key="one"
                    type="button"
                    class="cw-seg-item"
                    :aria-pressed="method === one"
                    @click="method = one"
                >
                    {{ t(`buyMethod_${one}`) }}
                </button>
            </div>

            <!-- Who will sell it, and for how much. -->
            <template v-if="chainOn">
                <div class="cw-row" style="margin-top: 24px">
                    <span class="cw-label">{{ t('buyOffers') }}</span>
                    <span
                        v-if="advantage !== null && advantage > 0.1"
                        class="cw-data"
                        style="color: var(--cw-accent)"
                        >+{{ advantage.toFixed(2) }}%</span
                    >
                </div>

                <p v-if="!amountOk" class="cw-prose" style="margin-top: 10px">
                    {{ t('buyAmountBad') }}
                </p>
                <p
                    v-else-if="quoting && offers.length === 0"
                    class="cw-label"
                    style="margin-top: 10px; color: var(--cw-faint)"
                >
                    {{ t('buyQuoting') }}
                </p>

                <div
                    v-else
                    class="cw-stack"
                    style="gap: 10px; margin-top: 10px"
                >
                    <div
                        v-for="offer in offers"
                        :key="offer.provider"
                        class="cw-card"
                        :style="
                            offer.best
                                ? 'border-color: var(--cw-accent)'
                                : offer.available
                                  ? ''
                                  : 'opacity: .62'
                        "
                    >
                        <div class="cw-row">
                            <span class="cw-data" style="color: var(--cw-text)">
                                {{ offer.label
                                }}<template v-if="offer.via">
                                    · {{ offer.via }}</template
                                >
                            </span>
                            <span
                                v-if="offer.available"
                                class="cw-data"
                                style="color: var(--cw-text)"
                                >{{ offer.cryptoAmount }} {{ row?.asset }}</span
                            >
                            <span v-else class="cw-label">—</span>
                        </div>

                        <div
                            v-if="offer.available"
                            class="cw-rowdata"
                            style="margin-top: 8px"
                        >
                            <template v-if="offer.fee"
                                >{{ t('buyFee') }} {{ offer.fee }}
                                {{ fiat }}</template
                            >
                            <template v-if="offer.tracking === 'none'">
                                · {{ t('buyNoTracking') }}</template
                            >
                        </div>
                        <p v-else class="cw-prose" style="margin-top: 8px">
                            {{ reasonOf(offer.reason) }}
                        </p>

                        <button
                            v-if="offer.available"
                            type="button"
                            class="cw-btn"
                            :class="
                                offer.best
                                    ? 'cw-btn-primary'
                                    : 'cw-btn-secondary'
                            "
                            style="margin-top: 12px"
                            :disabled="!chainOn || starting !== null"
                            @click="buy(offer.provider)"
                        >
                            {{
                                starting === offer.provider
                                    ? t('buyOpening')
                                    : t('buyWith', { provider: offer.label })
                            }}
                        </button>
                    </div>
                </div>

                <!-- Read before leaving for a page that is not ours. -->
                <p class="cw-prose" style="margin-top: 18px">
                    {{ t('buyHandoff') }}
                </p>
            </template>

            <div
                v-if="!mirFirst"
                class="cw-card"
                style="margin-top: 18px; opacity: 0.8"
            >
                <div class="cw-row">
                    <span class="cw-label">{{ t('buyMir') }}</span>
                    <span class="cw-label">—</span>
                </div>
                <p class="cw-prose" style="margin-top: 8px">
                    {{
                        catalogue.mir.available
                            ? t('buyMirExternal')
                            : t('buyReason_mir_unserved')
                    }}
                </p>
            </div>
        </template>
    </div>
</template>
