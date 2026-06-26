<script setup lang="ts">
import { Link as InertiaLink, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, ExternalLink, LogOut, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';
import { logout as logoutRoute } from '@/routes';

const page = usePage();
const currentUrl = computed(() => page.url || '/');

const evmWallet = useWallet();
const solanaWallet = useSolanaWallet();
const walletAuth = useWalletAuth();

const isAuthenticated = computed(() => !!page.props.auth?.user);
const authUser = computed(
    () =>
        page.props.auth?.user as
            | {
                  name?: string;
                  wallet_address?: string | null;
                  solana_wallet_address?: string | null;
              }
            | undefined,
);

const isAuthenticating = ref(false);
const authError = ref<string | null>(null);

const displayAddress = computed(() => {
    const addr =
        authUser.value?.wallet_address ||
        authUser.value?.solana_wallet_address ||
        evmWallet.address.value ||
        solanaWallet.address.value;

    if (!addr) {
        return null;
    }

    return `${addr.slice(0, 6)}...${addr.slice(-4)}`;
});

function isActive(href: string): boolean {
    if (href === '/') {
        return currentUrl.value === '/' || currentUrl.value === '';
    }

    return (
        currentUrl.value === href || currentUrl.value.startsWith(href + '/')
    );
}

function linkClass(href: string): string {
    return isActive(href)
        ? 'font-medium hover:underline'
        : 'text-muted-foreground hover:text-foreground';
}

async function connectMetaMask() {
    authError.value = null;

    if (!evmWallet.isMetaMaskInstalled()) {
        authError.value = 'MetaMask is not installed';

        return;
    }

    isAuthenticating.value = true;

    try {
        const address = await evmWallet.connect();

        if (!address) {
            authError.value = evmWallet.error.value || 'Failed to connect';
            isAuthenticating.value = false;

            return;
        }

        const { nonce } = await walletAuth.generateNonce(address);
        const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
        const signature = await evmWallet.signMessage(message);

        if (!signature) {
            authError.value = 'Failed to sign message';
            isAuthenticating.value = false;

            return;
        }

        const { token } = await walletAuth.verifySignature(address, signature);

        router.post(
            '/login/web3',
            { token },
            {
                onError: (err: Record<string, string>) => {
                    authError.value = err.message || 'Authentication failed';
                },
                onFinish: () => {
                    isAuthenticating.value = false;
                },
            },
        );
    } catch (err) {
        authError.value =
            err instanceof Error ? err.message : 'Authentication failed';
        isAuthenticating.value = false;
    }
}

async function connectPhantom() {
    authError.value = null;

    if (!solanaWallet.isPhantomInstalled()) {
        authError.value = 'Phantom is not installed';

        return;
    }

    isAuthenticating.value = true;

    try {
        const address = await solanaWallet.connect();

        if (!address) {
            authError.value = solanaWallet.error.value || 'Failed to connect';
            isAuthenticating.value = false;

            return;
        }

        const { nonce } = await walletAuth.generateSolanaNonce(address);
        const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
        const signature = await solanaWallet.signMessage(message);

        if (!signature) {
            authError.value = 'Failed to sign message';
            isAuthenticating.value = false;

            return;
        }

        const { token } = await walletAuth.verifySolanaSignature(
            address,
            signature,
        );

        router.post(
            '/login/web3',
            { token },
            {
                onError: (err: Record<string, string>) => {
                    authError.value = err.message || 'Authentication failed';
                },
                onFinish: () => {
                    isAuthenticating.value = false;
                },
            },
        );
    } catch (err) {
        authError.value =
            err instanceof Error ? err.message : 'Authentication failed';
        isAuthenticating.value = false;
    }
}

function signOut() {
    evmWallet.disconnect();
    solanaWallet.disconnect();
    router.post(logoutRoute().url);
}
</script>

<template>
    <header class="border-b">
        <nav
            class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-4 text-sm"
            aria-label="Cyberia navigation"
        >
            <a href="/" :class="linkClass('/')">Cyberia</a>
            <InertiaLink href="/bridge" :class="linkClass('/bridge')"
                >Bridge</InertiaLink
            >
            <InertiaLink href="/convert" :class="linkClass('/convert')"
                >Convert</InertiaLink
            >
            <InertiaLink href="/dao" :class="linkClass('/dao')"
                >DAO</InertiaLink
            >
            <InertiaLink href="/market" :class="linkClass('/market')"
                >NFT Market</InertiaLink
            >
            <InertiaLink href="/lending" :class="linkClass('/lending')"
                >Lending</InertiaLink
            >
            <InertiaLink href="/farm" :class="linkClass('/farm')"
                >Farm</InertiaLink
            >
            <InertiaLink href="/launchpad" :class="linkClass('/launchpad')"
                >Launchpad</InertiaLink
            >
            <InertiaLink href="/analytics" :class="linkClass('/analytics')"
                >Analytics</InertiaLink
            >
            <a
                href="https://swap.cyberia.church/"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
            >
                Swap <ExternalLink class="h-3 w-3" />
            </a>
            <a
                href="https://cyberia.church/"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
            >
                cyberia.church <ExternalLink class="h-3 w-3" />
            </a>
            <a
                href="https://explorer.cyberia.church/"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 text-muted-foreground hover:text-foreground"
            >
                Explorer <ExternalLink class="h-3 w-3" />
            </a>

            <div class="ml-auto flex items-center gap-2">
                <slot name="actions" />

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            size="sm"
                            class="gap-2"
                            :disabled="isAuthenticating"
                        >
                            <Spinner
                                v-if="isAuthenticating"
                                class="h-4 w-4"
                            />
                            <Wallet v-else class="h-4 w-4" />
                            <span
                                v-if="isAuthenticated && displayAddress"
                                class="font-mono"
                                >{{ displayAddress }}</span
                            >
                            <span v-else>Connect Wallet</span>
                            <ChevronDown class="h-3 w-3 opacity-60" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <template v-if="isAuthenticated">
                            <DropdownMenuItem
                                disabled
                                class="font-mono text-xs"
                            >
                                {{ displayAddress }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem @select="signOut">
                                <LogOut class="mr-2 h-4 w-4" />
                                Sign out
                            </DropdownMenuItem>
                        </template>
                        <template v-else>
                            <DropdownMenuItem @select="connectMetaMask">
                                <Wallet class="mr-2 h-4 w-4" />
                                MetaMask
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="connectPhantom">
                                <Wallet class="mr-2 h-4 w-4" />
                                Phantom
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </nav>

        <p
            v-if="authError"
            class="mx-auto max-w-6xl px-4 pb-2 text-xs text-destructive"
        >
            {{ authError }}
        </p>
    </header>
</template>
