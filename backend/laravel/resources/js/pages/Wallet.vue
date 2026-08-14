<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { ExternalLink, Languages, Lock } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import NetworkMark from '@/components/wallet/NetworkMark.vue';
import WalletAccounts from '@/components/wallet/WalletAccounts.vue';
import WalletAddNetwork from '@/components/wallet/WalletAddNetwork.vue';
import WalletAnalytics from '@/components/wallet/WalletAnalytics.vue';
import WalletBridge from '@/components/wallet/WalletBridge.vue';
import WalletBrowse from '@/components/wallet/WalletBrowse.vue';
import WalletChat from '@/components/wallet/WalletChat.vue';
import WalletDao from '@/components/wallet/WalletDao.vue';
import WalletEarn from '@/components/wallet/WalletEarn.vue';
import WalletFeed from '@/components/wallet/WalletFeed.vue';
import WalletGasStation from '@/components/wallet/WalletGasStation.vue';
import WalletImportAccount from '@/components/wallet/WalletImportAccount.vue';
import WalletIpfs from '@/components/wallet/WalletIpfs.vue';
import WalletLain from '@/components/wallet/WalletLain.vue';
import WalletLaunchpad from '@/components/wallet/WalletLaunchpad.vue';
import WalletLocked from '@/components/wallet/WalletLocked.vue';
import WalletNetworkDetail from '@/components/wallet/WalletNetworkDetail.vue';
import WalletNft from '@/components/wallet/WalletNft.vue';
import WalletNftMint from '@/components/wallet/WalletNftMint.vue';
import WalletOnboarding from '@/components/wallet/WalletOnboarding.vue';
import WalletPortfolio from '@/components/wallet/WalletPortfolio.vue';
import WalletProfile from '@/components/wallet/WalletProfile.vue';
import WalletProxy from '@/components/wallet/WalletProxy.vue';
import WalletReceive from '@/components/wallet/WalletReceive.vue';
import WalletSecurity from '@/components/wallet/WalletSecurity.vue';
import WalletSend from '@/components/wallet/WalletSend.vue';
import WalletSwap from '@/components/wallet/WalletSwap.vue';
import WalletToken from '@/components/wallet/WalletToken.vue';
import WalletTokens from '@/components/wallet/WalletTokens.vue';
import WalletTorrent from '@/components/wallet/WalletTorrent.vue';
import { useLocale } from '@/composables/useLocale';
import { useMultiWallet } from '@/composables/useMultiWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';
import { isNativeShell, nativeShell } from '@/lib/native';
import {
    hideMainButton,
    setBackButton,
    setMainButton,
    telegramHaptic,
} from '@/lib/telegram';
import { unreadChatCount } from '@/lib/wallet';
import type { WalletChainId, WalletTokenBalance } from '@/lib/wallet';
import type { BridgeConfig } from '@/lib/wallet/bridge';
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
    /** Which contract the holders' room counts, and how much of it it wants. */
    lain: {
        enabled: boolean;
        tokenAddress: string;
        minimumShareBps: number;
    };
    /** Which bridge corridors exist, which are open, and where deposits go. */
    bridge: BridgeConfig;
    /** The limits this server pins under, so the screens can say them first. */
    ipfs: {
        enabled: boolean;
        maxBytes: number;
        gateway: string;
    };
}>();

const { nextTag, toggleLocale, t } = useLocale(walletMessages);

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

type Section =
    | 'portfolio'
    | 'tokens'
    | 'token'
    | 'analytics'
    | 'chat'
    | 'accounts'
    | 'importAccount'
    | 'network'
    | 'security'
    | 'feed'
    | 'profile'
    | 'launchpad'
    | 'dao'
    | 'lain'
    | 'nft'
    | 'nftMint'
    | 'ipfs'
    | 'torrent'
    | 'gas'
    | 'proxy'
    | 'earn'
    | 'bridge'
    | 'browse';
type Overlay = 'send' | 'receive' | 'swap' | 'addNetwork';

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
    { id: 'tokens', label: () => t('tokens') },
    { id: 'analytics', label: () => t('navAnalytics') },
    { id: 'chat', label: () => t('chatTitle') },
    { id: 'network', label: () => t('navActivity') },
    { id: 'accounts', label: () => t('accounts') },
    // Three things done *with* a balance rather than three ways of reading
    // one, which is why they sit apart from the screens above them.
    { id: 'bridge', label: () => t('bridgeTitle') },
    { id: 'earn', label: () => t('earnTitle') },
    { id: 'browse', label: () => t('browseTitle') },
    { id: 'feed', label: () => t('feed') },
    { id: 'launchpad', label: () => t('launchpad') },
    { id: 'nft', label: () => t('nftTitle') },
    { id: 'dao', label: () => t('dao') },
    // Listed even for wallets that hold no $LAIN: the room says what it wants
    // and what this account has, which is the only way to know it exists.
    { id: 'lain', label: () => t('navLain') },
    { id: 'security', label: () => t('navSecurity') },
];

/**
 * The phone's destinations, and they are what this app is: the wallet, the
 * messages that wallet sends, the feed, the launchpad, what you own that is
 * not fungible, the DAO and Lain.
 *
 * Messages started life as a shortcut on the portfolio, on the theory that five
 * labels are all 390px holds. That was wrong in the way that matters: a
 * correspondence is not a way of reading your holdings, and nobody looks for it
 * inside them. NFT is here for the same reason — a thing you own is not a way
 * of reading a balance, and it is where minting, pinning and downloading are
 * reached from.
 *
 * Seven labels is ~56px each on a 390px phone, which the bar spends its
 * tracking and then a point of its font size to hold; the label is what
 * survives, because a tab nobody can read is not a destination.
 *
 * Everything else genuinely is a place inside one of these: tokens, analytics,
 * accounts and security are all ways of reading the wallet, reached from the
 * portfolio.
 */
const TABS: { id: Section; label: () => string }[] = [
    { id: 'portfolio', label: () => t('tabWallet') },
    { id: 'chat', label: () => t('tabChat') },
    { id: 'feed', label: () => t('feed') },
    { id: 'launchpad', label: () => t('tabLaunch') },
    { id: 'nft', label: () => t('nftTitle') },
    { id: 'dao', label: () => t('dao') },
    { id: 'lain', label: () => t('navLain') },
];

/** Which tab a screen lives under, for the bar's active state. */
const TAB_OF: Record<Section, Section> = {
    portfolio: 'portfolio',
    tokens: 'portfolio',
    token: 'portfolio',
    analytics: 'portfolio',
    chat: 'chat',
    accounts: 'portfolio',
    importAccount: 'portfolio',
    network: 'portfolio',
    security: 'portfolio',
    gas: 'portfolio',
    proxy: 'portfolio',
    earn: 'portfolio',
    bridge: 'portfolio',
    browse: 'browse',
    feed: 'feed',
    profile: 'feed',
    launchpad: 'launchpad',
    dao: 'dao',
    lain: 'lain',
    nft: 'nft',
    nftMint: 'nft',
    ipfs: 'nft',
    torrent: 'nft',
};

const activeTab = computed(() => TAB_OF[section.value]);

/* ------------------------------------------------------- telegram mini app --- */

/**
 * Inside Telegram the frame is not ours: the header carries a back arrow and
 * the bottom carries one main button, and an app that leaves those two wired to
 * nothing is an app that closes itself when someone tries to go back.
 */
const telegram = nativeShell() === 'telegram';

/** Where "back" goes, or null on a screen that is already the top of the app. */
const telegramBack = computed<(() => void) | null>(() => {
    if (overlay.value !== null) {
        return () => {
            overlay.value = null;
        };
    }

    // A screen that was opened from another one goes back to that one, which
    // is the only answer that matches what the user did to get here.
    const parent = PARENTS[section.value];

    if (parent) {
        return () => openSection(parent);
    }

    if (section.value !== 'portfolio' && activeTab.value === 'portfolio') {
        return () => openSection('portfolio');
    }

    return null;
});

if (telegram) {
    let releaseMain = (): void => {};
    let releaseBack = (): void => {};

    watch(
        // The main button mirrors the screen's primary action, and *only*
        // where that action is a tap. Signing is a hold in this wallet — a
        // gesture a page cannot perform and a thumb cannot perform by accident
        // — so the signing screens leave Telegram's button hidden rather than
        // quietly demoting a hold to a tap.
        () => [stage.value, section.value, overlay.value] as const,
        () => {
            releaseMain();

            if (
                stage.value === 'app' &&
                section.value === 'portfolio' &&
                overlay.value === null
            ) {
                releaseMain = setMainButton({
                    text: t('send').toUpperCase(),
                    onClick: () => {
                        telegramHaptic();
                        openSend();
                    },
                });
            } else {
                hideMainButton();
                releaseMain = () => {};
            }
        },
        { immediate: true },
    );

    watch(
        telegramBack,
        (handler) => {
            releaseBack();
            releaseBack = setBackButton(handler);
        },
        { immediate: true },
    );

    onBeforeUnmount(() => {
        releaseMain();
        releaseBack();
    });
}

/**
 * Messages waiting, for the badge on the tab bar and the rail.
 *
 * Counted from the envelopes already cached on this device — the metadata
 * around a message, never what is inside one — so the number costs no key and
 * survives a locked vault. It is a `ref` and not a computed because
 * localStorage is not reactive: the chat screen says when it has changed, and
 * an account switch changes whose mail this is.
 *
 * It deliberately does not poll the relay in the background. Asking for mail
 * takes a signed proof, and a wallet that signs things nobody asked for is
 * worse than a badge that is one visit out of date.
 */
const unread = ref(0);

const refreshUnread = (): void => {
    const evm = wallet.accounts.value.find(
        (account) => account.family === 'evm',
    )?.address;

    unread.value = evm ? unreadChatCount(evm) : 0;
};

/**
 * The network chip only belongs above the screens that are about one network.
 * On the feed or in the DAO it would be answering a question nobody asked.
 */
const showNetworkBar = computed(
    () => activeTab.value === 'portfolio' || overlay.value !== null,
);

const openSection = (next: Section): void => {
    section.value = next;
    overlay.value = null;
};

/** Whose profile the profile screen is about — null means this wallet's own. */
const profileAddress = ref<string | null>(null);

const openProfile = (address: string | null): void => {
    profileAddress.value = address;
    openSection('profile');
};

/**
 * Which navigation entry a screen belongs under. A few screens are places you
 * arrive at from another one rather than destinations of their own, so the
 * navigation keeps pointing at where they came from.
 */
const PARENTS: Partial<Record<Section, Section>> = {
    token: 'tokens',
    importAccount: 'accounts',
    gas: 'portfolio',
    proxy: 'security',
    earn: 'portfolio',
    bridge: 'portfolio',
    profile: 'feed',
    nftMint: 'nft',
    ipfs: 'nft',
    torrent: 'nft',
};

const current = computed<Section>(
    () => PARENTS[section.value] ?? section.value,
);

/** The asset the send screen opens on: a token row, or the network's coin. */
const sendToken = ref<WalletTokenBalance | null>(null);

/** The contract the token screen is about, and where it was opened from. */
const tokenContract = ref<string | null>(null);
const tokenOrigin = ref<Section>('tokens');

const openSend = (token?: WalletTokenBalance): void => {
    sendToken.value = token ?? null;
    overlay.value = 'send';
};

/**
 * Trading, from wherever the asset was tapped. A swap belongs to one network —
 * it is that network's router, that network's pools and that network's gas —
 * so it opens as a composer over the network the asset lives on, exactly like
 * sending does.
 */
const swapToken = ref<WalletTokenBalance | null>(null);

/** A contract to buy, from a screen that knows an address and nothing else. */
const swapContract = ref<string | null>(null);

const openSwap = (token?: WalletTokenBalance): void => {
    swapToken.value = token ?? null;
    swapContract.value = null;
    overlay.value = 'swap';
};

/**
 * Buying one specific token — a launch, so far. It lives on the launchpad's
 * chain, and the swap screen reads the contract itself rather than trusting
 * the row that was tapped for anything but its address.
 */
const openSwapContract = (contract: string): void => {
    swapToken.value = null;
    swapContract.value = contract;
    chain.value = 'cyberia';
    overlay.value = 'swap';
};

/**
 * A CID the mint screen should open with.
 *
 * Publishing a file and minting a token that points at it are two acts, and
 * the second one costs gas — so the CID travels between the screens rather
 * than one screen quietly doing both.
 */
const mintPreset = ref<string | null>(null);

const openMint = (uri: string | null): void => {
    mintPreset.value = uri;
    openSection('nftMint');
};

const openChain = (next: WalletChainId): void => {
    chain.value = next;
    section.value = 'network';
    overlay.value = null;
};

/**
 * One token, from wherever it was tapped. A token is only ever reached through
 * a network — its own or the list's — so the chain moves with it and the send
 * screen that opens next is already pointed at the right account.
 */
const openToken = (next: WalletChainId, token: WalletTokenBalance): void => {
    tokenOrigin.value = section.value === 'network' ? 'network' : 'tokens';
    chain.value = next;
    tokenContract.value = token.address;
    section.value = 'token';
    overlay.value = null;
};

const sendChainToken = (
    next: WalletChainId,
    token: WalletTokenBalance,
): void => {
    chain.value = next;
    openSend(token);
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
    refreshUnread();
    void load();
});

onBeforeUnmount(() => {
    window.removeEventListener('online', trackConnection);
    window.removeEventListener('offline', trackConnection);
});

/**
 * Switching accounts reloads everything, because everything on screen belonged
 * to the account that was active a moment ago.
 *
 * An imported key or a watched address exists on one chain only, so a screen
 * still pointing at a network this account does not have is moved to one it
 * does — otherwise the network detail would render an account that is not
 * there.
 */
watch(
    () => wallet.activeAccountId.value,
    () => {
        if (
            !wallet.accounts.value.some(
                (account) => account.chain === chain.value,
            )
        ) {
            chain.value = wallet.accounts.value[0]?.chain ?? 'cyberia';
        }

        refreshUnread();
        void load();
    },
);

// A vault that has just been unlocked has nothing loaded behind it yet.
watch(
    () => wallet.unlocked.value,
    (unlocked) => {
        refreshUnread();

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
                ? 'flex min-h-0 flex-1 flex-col p-3 sm:p-4'
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
                {{ nextTag }}
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
                            current === entry.id ? 'page' : undefined
                        "
                        @click="openSection(entry.id)"
                    >
                        {{ entry.label() }}
                        <span
                            v-if="entry.id === 'chat' && unread > 0"
                            class="cw-badge"
                            >{{ unread }}</span
                        >
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
                    v-if="stage === 'app' && showNetworkBar"
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
                            :telegram="telegram"
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

                    <WalletSwap
                        v-else-if="bodyOverlay === 'swap'"
                        :wallet="wallet"
                        :chain="chain"
                        :prices="prices"
                        :token-prices="tokenPrices"
                        :token="swapToken"
                        :contract="swapContract"
                        @back="overlay = null"
                        @pick="chain = $event"
                        @swapped="load()"
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

                    <!--
                        Arriving from a chat, the first question is what
                        Telegram can see. It is answered before the balances,
                        not in a policy page nobody opens.
                    -->
                    <template v-else-if="section === 'portfolio'">
                        <p
                            v-if="telegram"
                            class="cw-note"
                            style="margin-bottom: 16px"
                        >
                            {{ t('tgCustody') }}
                        </p>

                        <WalletPortfolio
                            :wallet="wallet"
                            :prices="prices"
                            :token-prices="tokenPrices"
                            :online="online"
                            @open="openChain"
                            @send="openSend()"
                            @swap="openSwap()"
                            @receive="overlay = 'receive'"
                            @add-network="overlay = 'addNetwork'"
                            @tokens="openSection('tokens')"
                            @analytics="openSection('analytics')"
                            @accounts="openSection('accounts')"
                            @security="openSection('security')"
                            @gas="openSection('gas')"
                            @earn="openSection('earn')"
                            @bridge="openSection('bridge')"
                            @browse="openSection('browse')"
                        />
                    </template>

                    <WalletChat
                        v-else-if="section === 'chat'"
                        :wallet="wallet"
                        @unread="refreshUnread"
                    />

                    <WalletAccounts
                        v-else-if="section === 'accounts'"
                        :wallet="wallet"
                        @back="openSection('portfolio')"
                        @import="openSection('importAccount')"
                    />

                    <WalletImportAccount
                        v-else-if="section === 'importAccount'"
                        :wallet="wallet"
                        @back="openSection('accounts')"
                        @imported="
                            openSection('accounts');
                            load();
                        "
                    />

                    <WalletTokens
                        v-else-if="section === 'tokens'"
                        :wallet="wallet"
                        :token-prices="tokenPrices"
                        @back="openSection('portfolio')"
                        @open="openToken"
                        @send="sendChainToken"
                    />

                    <WalletToken
                        v-else-if="section === 'token' && tokenContract"
                        :wallet="wallet"
                        :chain="chain"
                        :address="tokenContract"
                        :prices="tokenPrices[chain] ?? {}"
                        @back="openSection(tokenOrigin)"
                        @send="openSend"
                        @swap="openSwap"
                        @hidden="openSection(tokenOrigin)"
                    />

                    <!--
                      Sponsored fees as a place, not only as the offer that
                      appears mid-transaction: what the station is, and why it
                      said no, are questions asked when nobody is signing.
                    -->
                    <WalletGasStation
                        v-else-if="section === 'gas'"
                        :wallet="wallet"
                        :prices="prices"
                        @back="openSection('portfolio')"
                    />

                    <!--
                      The directory of what is on this chain — and the plain
                      answer to what a page can and cannot ask a wallet for.
                    -->
                    <WalletBrowse
                        v-else-if="section === 'browse'"
                        :wallet="wallet"
                        @swap="openSwap()"
                        @earn="openSection('earn')"
                        @launchpad="openSection('launchpad')"
                        @dao="openSection('dao')"
                        @bridge="openSection('bridge')"
                    />

                    <!--
                      Leaving the chain: one transfer signed here, one payout
                      made there, and nothing in between that can be undone.
                    -->
                    <WalletBridge
                        v-else-if="section === 'bridge'"
                        :wallet="wallet"
                        :config="props.bridge"
                        :prices="prices"
                        @back="openSection('portfolio')"
                    />

                    <!--
                      A pool position is not a balance: it is money at work,
                      with its own risk and its own unclaimed half.
                    -->
                    <WalletEarn
                        v-else-if="section === 'earn'"
                        :wallet="wallet"
                        :chain="chain"
                        :prices="prices"
                        @back="openSection('portfolio')"
                    />

                    <WalletAnalytics
                        v-else-if="section === 'analytics'"
                        :wallet="wallet"
                        :prices="prices"
                        :token-prices="tokenPrices"
                        @back="openSection('portfolio')"
                    />

                    <WalletNetworkDetail
                        v-else-if="section === 'network'"
                        :wallet="wallet"
                        :chain="chain"
                        :prices="prices"
                        :token-prices="tokenPrices"
                        @back="openSection('portfolio')"
                        @send="openSend"
                        @swap="openSwap()"
                        @receive="overlay = 'receive'"
                        @open-token="openToken(chain, $event)"
                    />

                    <WalletFeed
                        v-else-if="section === 'feed'"
                        @profile="openProfile"
                    />

                    <WalletProfile
                        v-else-if="section === 'profile'"
                        :wallet="wallet"
                        :address="profileAddress"
                        @back="openSection('feed')"
                    />

                    <WalletLaunchpad
                        v-else-if="section === 'launchpad'"
                        :prices="prices"
                        @swap="openSwapContract"
                    />

                    <WalletDao v-else-if="section === 'dao'" />

                    <!--
                      One tab for the things that are not balances: what this
                      account owns, where a file can be published, and how a
                      file gets here in the first place.
                    -->
                    <WalletNft
                        v-else-if="section === 'nft'"
                        :wallet="wallet"
                        @mint="openMint(null)"
                        @ipfs="openSection('ipfs')"
                        @torrents="openSection('torrent')"
                    />

                    <WalletNftMint
                        v-else-if="section === 'nftMint'"
                        :wallet="wallet"
                        :ipfs="props.ipfs"
                        :preset="mintPreset"
                        @back="openSection('nft')"
                        @minted="load()"
                    />

                    <WalletIpfs
                        v-else-if="section === 'ipfs'"
                        :ipfs="props.ipfs"
                        @back="openSection('nft')"
                        @mint="openMint"
                    />

                    <WalletTorrent
                        v-else-if="section === 'torrent'"
                        @back="openSection('nft')"
                        @mint="openMint"
                    />

                    <WalletLain
                        v-else-if="section === 'lain'"
                        :wallet="wallet"
                        :config="props.lain"
                    />

                    <!--
                      Where the requests go, which is the half of privacy a
                      local key does not cover on its own.
                    -->
                    <WalletProxy
                        v-else-if="section === 'proxy'"
                        :wallet="wallet"
                        @back="openSection('security')"
                    />

                    <WalletSecurity
                        v-else
                        :wallet="wallet"
                        @locked="section = 'portfolio'"
                        @add-network="overlay = 'addNetwork'"
                        @proxy="openSection('proxy')"
                        @forgotten="
                            restoring = false;
                            section = 'portfolio';
                        "
                    />
                </div>

                <!-- Mobile tab bar -->
                <nav v-if="stage === 'app'" class="cw-tabs">
                    <button
                        v-for="entry in TABS"
                        :key="entry.id"
                        type="button"
                        class="cw-tab"
                        :aria-current="
                            activeTab === entry.id && overlay === null
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
                                    activeTab === entry.id && overlay === null
                                        ? 'currentColor'
                                        : 'transparent',
                            }"
                        />
                        <span class="cw-tab-label">{{ entry.label() }}</span>
                        <!--
                          Over the corner rather than beside the label: at 65px
                          a tab has no room for a second word, and a count that
                          pushed the label would be the one thing on this bar
                          that changes width while you read it.
                        -->
                        <span
                            v-if="entry.id === 'chat' && unread > 0"
                            class="cw-badge cw-tab-badge"
                            >{{ unread }}</span
                        >
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
                <WalletSwap
                    v-else-if="asideOverlay === 'swap'"
                    :wallet="wallet"
                    :chain="chain"
                    :prices="prices"
                    :token-prices="tokenPrices"
                    :token="swapToken"
                    :contract="swapContract"
                    @back="overlay = null"
                    @pick="chain = $event"
                    @swapped="load()"
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
