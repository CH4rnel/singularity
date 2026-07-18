<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ChevronDown, LogOut, User, Wallet } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
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
import { show as profileRoute } from '@/routes/profile';

const page = usePage();

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
const evmProviders = computed(() => evmWallet.walletProviders.value);
const solanaProviders = computed(() => solanaWallet.walletProviders.value);

function refreshWalletChoices() {
    evmWallet.refreshWalletProviders();
    solanaWallet.refreshWalletProviders();
}

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

async function connectEvm(providerId: string) {
    authError.value = null;

    isAuthenticating.value = true;

    try {
        const address = await evmWallet.connect(providerId);

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

async function connectSolana(providerId: string) {
    authError.value = null;

    isAuthenticating.value = true;

    try {
        const address = await solanaWallet.connect(providerId);

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

onMounted(refreshWalletChoices);
</script>

<template>
    <div class="relative">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="sm"
                    class="gap-2"
                    :disabled="isAuthenticating"
                    @click="refreshWalletChoices"
                >
                    <Spinner v-if="isAuthenticating" class="h-4 w-4" />
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
                    <DropdownMenuItem disabled class="font-mono text-xs">
                        {{ displayAddress }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        @select="router.visit(profileRoute().url)"
                    >
                        <User class="mr-2 h-4 w-4" />
                        Profile
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="signOut">
                        <LogOut class="mr-2 h-4 w-4" />
                        Sign out
                    </DropdownMenuItem>
                </template>
                <template v-else>
                    <DropdownMenuItem disabled class="text-xs font-semibold">
                        EVM wallets
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="provider in evmProviders"
                        :key="provider.id"
                        @select="connectEvm(provider.id)"
                    >
                        <img
                            v-if="provider.icon"
                            :src="provider.icon"
                            :alt="`${provider.name} icon`"
                            class="mr-2 h-4 w-4 rounded-sm"
                        />
                        <Wallet v-else class="mr-2 h-4 w-4" />
                        {{ provider.name }}
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="evmProviders.length === 0" disabled>
                        <Wallet class="mr-2 h-4 w-4" />
                        No EVM wallet found
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem disabled class="text-xs font-semibold">
                        Solana wallets
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="provider in solanaProviders"
                        :key="provider.id"
                        @select="connectSolana(provider.id)"
                    >
                        <img
                            v-if="provider.icon"
                            :src="provider.icon"
                            :alt="`${provider.name} icon`"
                            class="mr-2 h-4 w-4 rounded-sm"
                        />
                        <Wallet v-else class="mr-2 h-4 w-4" />
                        {{ provider.name }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="solanaProviders.length === 0"
                        disabled
                    >
                        <Wallet class="mr-2 h-4 w-4" />
                        No Solana wallet found
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

        <p
            v-if="authError"
            class="absolute top-full right-0 z-50 mt-1 w-64 rounded-md border bg-popover px-3 py-2 text-xs text-destructive shadow-md"
        >
            {{ authError }}
        </p>
    </div>
</template>
