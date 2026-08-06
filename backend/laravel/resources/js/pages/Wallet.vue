<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { ExternalLink, Languages, Lock } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NetworkMark from '@/components/wallet/NetworkMark.vue';
import WalletAddNetwork from '@/components/wallet/WalletAddNetwork.vue';
import WalletLocked from '@/components/wallet/WalletLocked.vue';
import WalletNetworkDetail from '@/components/wallet/WalletNetworkDetail.vue';
import WalletOnboarding from '@/components/wallet/WalletOnboarding.vue';
import WalletPortfolio from '@/components/wallet/WalletPortfolio.vue';
import WalletReceive from '@/components/wallet/WalletReceive.vue';
import WalletSecurity from '@/components/wallet/WalletSecurity.vue';
import WalletSend from '@/components/wallet/WalletSend.vue';
import { useLocale } from '@/composables/useLocale';
import { useMultiWallet } from '@/composables/useMultiWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';
import { isNativeShell } from '@/lib/native';
import type { WalletChainId, WalletTokenBalance } from '@/lib/wallet';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The unified multichain wallet — and the home screen of the desktop and
 * mobile apps.
 *
 * One seed phrase, three networks, and a console that stays dark in either
 * site theme because the whole visual language depends on it. All three shells
 * render this same page: inside a native shell it drops the site chrome and
 * fills the frame, in a browser it sits inside the site like any other page.
 *
 * The server is deliberately almost absent. It hands over a public Solana RPC
 * URL, USD quotes and whatever XMR payout address is already on the profile;
 * balances, history, fees, signing and the seed itself never leave the browser.
 * The route is public for the same reason: a local key must not be gated behind
 * an account on a server that never sees it.
 */

const props = defineProps<{
    solanaRpcUrl: string;
    moneroPayoutAddress: string | null;
    quotes: {
        prices: Record<string, number | null>;
        /** Chain id → (lowercased contract → USD price). */
        tokens: Record<string, Record<string, number>>;
        fetchedAt: string;
    };
}>();

const { locale, toggleLocale, t } = useLocale(walletMessages);

// Only Solana takes an override: the server picks that endpoint, while every
// other chain carries its own public default in the registry.
const wallet = useMultiWallet({ solana: props.solanaRpcUrl });
const walletAuth = useWalletAuth();

const page = usePage();

/** Signing in adds only the XMR payout binding; the wallet itself needs none. */
const authenticated = computed(() => !!page.props.auth?.user);

/** Inside the desktop or mobile app the wallet owns the whole window. */
const native = isNativeShell();

const desktop = useMediaQuery('(min-width: 1024px)');

type Section = 'portfolio' | 'network' | 'security';
type Overlay = 'send' | 'receive' | 'addNetwork';

const section = ref<Section>('portfolio');
const overlay = ref<Overlay | null>(null);
const chain = ref<WalletChainId>('cyberia');
const prices = ref(props.quotes.prices);
const tokenPrices = ref(props.quotes.tokens ?? {});
const online = ref(true);
const error = ref<string | null>(null);
const payoutSaved = ref(false);

/**
 * Restoring from seed while a vault already exists — the only way back in
 * after a forgotten password, and the reason that path is offered at all.
 */
const restoring = ref(false);

const stage = computed<'onboarding' | 'locked' | 'app'>(() => {
    if (restoring.value || !wallet.exists.value) {
        return 'onboarding';
    }

    return wallet.unlocked.value ? 'app' : 'locked';
});

/**
 * On desktop the transfer flow is a third column; on mobile it takes over.
 * Adding a network is a form rather than a composer, so it always takes the
 * body — a 392px column would wrap every one of its paired fields.
 */
const asideOverlay = computed(() =>
    desktop.value && overlay.value !== 'addNetwork' ? overlay.value : null,
);

const bodyOverlay = computed(() =>
    overlay.value === 'addNetwork' || !desktop.value ? overlay.value : null,
);

const SECTIONS: { id: Section; label: () => string }[] = [
    { id: 'portfolio', label: () => t('navPortfolio') },
    { id: 'network', label: () => t('navActivity') },
    { id: 'security', label: () => t('navSecurity') },
];

const openSection = (next: Section): void => {
    section.value = next;
    overlay.value = null;
};

/** The asset the send screen opens on: a token row, or the network's coin. */
const sendToken = ref<WalletTokenBalance | null>(null);

const openSend = (token?: WalletTokenBalance): void => {
    sendToken.value = token ?? null;
    overlay.value = 'send';
};

const openChain = (next: WalletChainId): void => {
    chain.value = next;
    section.value = 'network';
    overlay.value = null;
};

const refreshPrices = async (): Promise<void> => {
    try {
        const response = await fetch('/api/wallet/prices', {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const quotes = (await response.json()) as {
                prices: Record<string, number | null>;
                tokens?: Record<string, Record<string, number>>;
            };

            prices.value = quotes.prices;
            tokenPrices.value = quotes.tokens ?? {};
        }
    } catch {
        // Quotes are a nicety; the balances underneath them are the product.
    }
};

/** Everything the unlocked app reads from chains, in one pass. */
const load = async (): Promise<void> => {
    if (!wallet.unlocked.value) {
        return;
    }

    await Promise.all([
        wallet.refreshBalances(),
        ...wallet.chains.value
            .filter((entry) => entry.fetchHistory)
            .map((entry) => wallet.refreshHistory(entry.id)),
        ...wallet.chains.value
            .filter((entry) => entry.fetchTokens)
            .map((entry) => wallet.refreshTokens(entry.id)),
        refreshPrices(),
    ]);
};

/** A network the user just derived opens on its own detail screen. */
const networkAdded = (added: WalletChainId): void => {
    overlay.value = null;
    openChain(added);
    void load();
};

const adopt = async (phrase: string, password: string): Promise<void> => {
    error.value = null;

    try {
        await wallet.adopt(phrase, password);
        restoring.value = false;
        section.value = 'portfolio';
        await load();
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
    }
};

const useForPayouts = async (address: string): Promise<void> => {
    error.value = null;

    try {
        await walletAuth.attachMoneroWallet(address);
        payoutSaved.value = true;
    } catch (failure) {
        error.value =
            failure instanceof Error ? failure.message : String(failure);
    }
};

const trackConnection = (): void => {
    online.value = navigator.onLine;
};

onMounted(() => {
    trackConnection();
    window.addEventListener('online', trackConnection);
    window.addEventListener('offline', trackConnection);
    void load();
});

onBeforeUnmount(() => {
    window.removeEventListener('online', trackConnection);
    window.removeEventListener('offline', trackConnection);
});

// A vault that has just been unlocked has nothing loaded behind it yet.
watch(
    () => wallet.unlocked.value,
    (unlocked) => {
        if (unlocked) {
            void load();
        } else {
            overlay.value = null;
            section.value = 'portfolio';
        }
    },
);
</script>

<template>
    <Head :title="t('wallet')" />

    <div
        class="cw"
        :class="
            native
                ? 'flex flex-1 flex-col p-3 sm:p-4'
                : 'mx-auto max-w-[1400px] p-4 sm:p-6'
        "
    >
        <!-- Masthead -->
        <header
            style="
                display: flex;
                align-items: flex-end;
                gap: 20px;
                flex-wrap: wrap;
                margin-bottom: 24px;
            "
        >
            <div style="display: flex; align-items: center; gap: 12px">
                <span
                    style="
                        display: flex;
                        width: 26px;
                        height: 26px;
                        align-items: center;
                        justify-content: center;
                        border: 1px solid var(--cw-accent);
                    "
                >
                    <span
                        style="
                            width: 8px;
                            height: 8px;
                            background: var(--cw-accent);
                        "
                    />
                </span>
                <span
                    style="
                        font: 500 13px/1 var(--cw-mono);
                        letter-spacing: 0.28em;
                        color: var(--cw-text);
                        text-transform: uppercase;
                    "
                    >{{ t('wallet') }}</span
                >
            </div>
            <span
                style="
                    flex: 1;
                    min-width: 40px;
                    height: 1px;
                    background: linear-gradient(
                        90deg,
                        var(--cw-border-soft),
                        transparent
                    );
                "
            />
            <span class="cw-label" style="letter-spacing: 0.14em">{{
                t('subtitle')
            }}</span>
            <button type="button" class="cw-ghost" @click="toggleLocale">
                <Languages :size="14" aria-hidden="true" />
                {{ locale === 'ru' ? 'EN' : 'RU' }}
            </button>
            <!--
              The app is the wallet, not only the wallet: without this the rest
              of Cyberia — bridge, swap, DAO — would be unreachable from inside
              the shell, which the site header handles in a browser.
            -->
            <a
                v-if="native"
                href="/"
                class="cw-ghost"
                style="text-decoration: none"
            >
                <ExternalLink :size="14" aria-hidden="true" />
                {{ t('openSite') }}
            </a>
        </header>

        <p v-if="error" class="cw-note cw-note-bad" style="margin-bottom: 16px">
            <span>{{ error }}</span>
        </p>

        <div
            class="cw-shell"
            :class="{ 'cw-shell-native': native }"
            @pointerdown="wallet.touch()"
            @keydown="wallet.touch()"
        >
            <!-- Desktop rail -->
            <nav v-if="stage === 'app'" class="cw-rail">
                <div
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        padding: 0 20px 26px;
                    "
                >
                    <span
                        style="
                            display: flex;
                            width: 20px;
                            height: 20px;
                            align-items: center;
                            justify-content: center;
                            border: 1px solid var(--cw-accent);
                        "
                    >
                        <span
                            style="
                                width: 6px;
                                height: 6px;
                                background: var(--cw-accent);
                            "
                        />
                    </span>
                    <span
                        class="cw-label"
                        style="letter-spacing: 0.22em; color: var(--cw-text)"
                        >{{ t('vaultTag') }}</span
                    >
                </div>

                <div
                    style="
                        padding: 0 12px;
                        display: flex;
                        flex-direction: column;
                    "
                >
                    <button
                        v-for="entry in SECTIONS"
                        :key="entry.id"
                        type="button"
                        class="cw-rail-item"
                        :aria-current="
                            section === entry.id ? 'page' : undefined
                        "
                        @click="openSection(entry.id)"
                    >
                        {{ entry.label() }}
                    </button>
                </div>

                <div style="margin-top: 28px; padding: 0 20px">
                    <div class="cw-label" style="margin-bottom: 14px">
                        {{ t('networks') }}
                    </div>
                    <div class="cw-stack" style="gap: 12px">
                        <button
                            v-for="account in wallet.accounts.value"
                            :key="account.chain"
                            type="button"
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                border: none;
                                background: none;
                                padding: 0;
                                cursor: pointer;
                            "
                            @click="openChain(account.chain)"
                        >
                            <NetworkMark :chain="account.chain" dot :size="7" />
                            <span class="cw-data">{{ account.label }}</span>
                            <span
                                class="cw-label"
                                style="
                                    margin-left: auto;
                                    color: var(--cw-faint);
                                "
                                >{{ account.symbol }}</span
                            >
                        </button>
                    </div>
                </div>

                <div class="cw-fill"></div>

                <div style="padding: 0 20px">
                    <div
                        style="
                            padding: 13px 14px;
                            border: 1px solid var(--cw-hairline);
                        "
                    >
                        <div
                            style="
                                display: flex;
                                align-items: center;
                                gap: 8px;
                                margin-bottom: 6px;
                            "
                        >
                            <span
                                style="
                                    width: 5px;
                                    height: 5px;
                                    border-radius: 50%;
                                    background: var(--cw-ok);
                                "
                            />
                            <span
                                class="cw-label"
                                style="letter-spacing: 0.14em"
                                >{{ t('localVault') }}</span
                            >
                        </div>
                        <div
                            style="
                                font: 400 10px/1.5 var(--cw-mono);
                                color: var(--cw-faint);
                            "
                        >
                            {{ t('autoLock') }}
                            {{ wallet.autoLockMinutes.value }}m ·
                            {{ t('storageValue') }}
                        </div>
                    </div>
                    <button
                        type="button"
                        class="cw-ghost"
                        style="width: 100%; margin-top: 10px"
                        @click="wallet.lock()"
                    >
                        <Lock :size="13" aria-hidden="true" />
                        {{ t('lock') }}
                    </button>
                </div>
            </nav>

            <div class="cw-main">
                <!-- Context bar: which network the current screen is about. -->
                <div
                    v-if="stage === 'app'"
                    class="cw-row"
                    style="
                        flex: none;
                        height: 46px;
                        padding: 0 20px;
                        border-bottom: 1px solid var(--cw-line);
                    "
                >
                    <span style="display: flex; align-items: center; gap: 8px">
                        <NetworkMark :chain="chain" dot :size="6" />
                        <span
                            style="
                                font: 500 11px/1 var(--cw-mono);
                                letter-spacing: 0.1em;
                            "
                            >{{
                                wallet.accounts.value.find(
                                    (account) => account.chain === chain,
                                )?.label
                            }}</span
                        >
                    </span>
                    <button
                        type="button"
                        class="cw-back"
                        style="letter-spacing: 0.12em"
                        @click="load()"
                    >
                        {{ t('refresh') }}
                    </button>
                </div>

                <div class="cw-body">
                    <div v-if="stage !== 'app'" class="cw-column">
                        <WalletOnboarding
                            v-if="stage === 'onboarding'"
                            :busy="wallet.busy.value"
                            :start="restoring ? 'import' : 'welcome'"
                            :cancellable="restoring"
                            @adopt="adopt"
                            @cancel="restoring = false"
                        />
                        <WalletLocked
                            v-else
                            :wallet="wallet"
                            @restore="restoring = true"
                        />
                    </div>

                    <WalletAddNetwork
                        v-else-if="bodyOverlay === 'addNetwork'"
                        :wallet="wallet"
                        @back="overlay = null"
                        @added="networkAdded"
                    />

                    <WalletSend
                        v-else-if="bodyOverlay === 'send'"
                        :wallet="wallet"
                        :chain="chain"
                        :prices="prices"
                        :token-prices="tokenPrices"
                        :token="sendToken"
                        @back="overlay = null"
                        @pick="chain = $event"
                        @sent="load()"
                        @add-network="overlay = 'addNetwork'"
                    />

                    <WalletReceive
                        v-else-if="bodyOverlay === 'receive'"
                        :wallet="wallet"
                        :chain="chain"
                        :monero-payout-address="props.moneroPayoutAddress"
                        :payout-saved="payoutSaved"
                        :authenticated="authenticated"
                        @back="overlay = null"
                        @pick="chain = $event"
                        @use-payout="useForPayouts"
                        @add-network="overlay = 'addNetwork'"
                    />

                    <WalletPortfolio
                        v-else-if="section === 'portfolio'"
                        :wallet="wallet"
                        :prices="prices"
                        :token-prices="tokenPrices"
                        :online="online"
                        @open="openChain"
                        @send="openSend()"
                        @receive="overlay = 'receive'"
                        @add-network="overlay = 'addNetwork'"
                    />

                    <WalletNetworkDetail
                        v-else-if="section === 'network'"
                        :wallet="wallet"
                        :chain="chain"
                        :prices="prices"
                        :token-prices="tokenPrices"
                        @back="openSection('portfolio')"
                        @send="openSend"
                        @receive="overlay = 'receive'"
                    />

                    <WalletSecurity
                        v-else
                        :wallet="wallet"
                        @locked="section = 'portfolio'"
                        @add-network="overlay = 'addNetwork'"
                        @forgotten="
                            restoring = false;
                            section = 'portfolio';
                        "
                    />
                </div>

                <!-- Mobile tab bar -->
                <nav v-if="stage === 'app'" class="cw-tabs">
                    <button
                        v-for="entry in SECTIONS"
                        :key="entry.id"
                        type="button"
                        class="cw-tab"
                        :aria-current="
                            section === entry.id && overlay === null
                                ? 'page'
                                : undefined
                        "
                        @click="openSection(entry.id)"
                    >
                        <span
                            style="
                                width: 8px;
                                height: 8px;
                                border: 1px solid currentColor;
                            "
                            :style="{
                                background:
                                    section === entry.id && overlay === null
                                        ? 'currentColor'
                                        : 'transparent',
                            }"
                        />
                        {{ entry.label() }}
                    </button>
                </nav>
            </div>

            <!-- Desktop composer column -->
            <aside v-if="stage === 'app' && asideOverlay" class="cw-aside">
                <WalletSend
                    v-if="asideOverlay === 'send'"
                    :wallet="wallet"
                    :chain="chain"
                    :prices="prices"
                    :token-prices="tokenPrices"
                    :token="sendToken"
                    @back="overlay = null"
                    @pick="chain = $event"
                    @sent="load()"
                    @add-network="overlay = 'addNetwork'"
                />
                <WalletReceive
                    v-else
                    :wallet="wallet"
                    :chain="chain"
                    :monero-payout-address="props.moneroPayoutAddress"
                    :payout-saved="payoutSaved"
                    :authenticated="authenticated"
                    @back="overlay = null"
                    @pick="chain = $event"
                    @use-payout="useForPayouts"
                    @add-network="overlay = 'addNetwork'"
                />
            </aside>

            <div class="cw-raster" aria-hidden="true"></div>
            <div class="cw-scan" aria-hidden="true"></div>
        </div>

        <p
            v-if="!native"
            class="cw-prose"
            style="margin-top: 16px; max-width: 80ch"
        >
            {{ t('intro') }}
        </p>
    </div>
</template>

<style src="../../css/wallet.css"></style>
