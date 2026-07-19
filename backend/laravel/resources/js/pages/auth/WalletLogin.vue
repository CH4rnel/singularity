<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Wallet, Shield, Zap } from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { useWeb3Login } from '@/composables/useWeb3Login';
import { mergeWalletChoices } from '@/lib/walletChoices';
import { register } from '@/routes';

defineOptions({
    layout: {
        title: 'Sign in with your wallet',
        description: 'Connect your Web3 wallet to authenticate',
    },
});

const evmWallet = useWallet();
const solanaWallet = useSolanaWallet();
const web3Login = useWeb3Login();

const walletChoices = computed(() =>
    mergeWalletChoices(
        evmWallet.walletProviders.value,
        solanaWallet.walletProviders.value,
    ),
);
const installedChoices = computed(() =>
    walletChoices.value.filter((choice) => choice.installed),
);
const suggestedChoices = computed(() =>
    walletChoices.value.filter((choice) => !choice.installed),
);
const isBusy = computed(
    () =>
        web3Login.isAuthenticating.value ||
        evmWallet.isConnecting.value ||
        solanaWallet.isConnecting.value,
);

function refreshWalletChoices() {
    evmWallet.refreshWalletProviders();
    solanaWallet.refreshWalletProviders();
}

onMounted(refreshWalletChoices);
</script>

<template>
    <Head title="Web3 Login" />

    <div class="flex min-h-[80vh] flex-col items-center justify-center gap-8">
        <div class="flex flex-col items-center gap-4 text-center">
            <div
                class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10"
            >
                <Wallet class="h-8 w-8 text-primary" />
            </div>
            <div>
                <h1 class="text-3xl font-bold">Sign in with your wallet</h1>
                <p class="mt-2 text-muted-foreground">
                    Choose any EVM or Solana wallet — browser extension or
                    mobile via WalletConnect
                </p>
            </div>
        </div>

        <Card class="w-full max-w-md">
            <CardHeader>
                <CardTitle>Connect your wallet</CardTitle>
                <CardDescription>
                    No password needed. Just sign a message with your wallet.
                </CardDescription>
            </CardHeader>
            <CardContent class="flex flex-col gap-6">
                <div class="grid gap-4">
                    <div class="flex items-center gap-3 rounded-lg border p-4">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10"
                        >
                            <Shield class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="font-medium">Secure authentication</p>
                            <p class="text-sm text-muted-foreground">
                                Sign a message to prove wallet ownership
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 rounded-lg border p-4">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10"
                        >
                            <Zap class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="font-medium">Instant access</p>
                            <p class="text-sm text-muted-foreground">
                                No registration required for new wallets
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Your wallets
                    </p>
                    <div
                        v-for="choice in installedChoices"
                        :key="choice.key"
                        class="flex items-center gap-3 rounded-lg border p-3"
                    >
                        <img
                            v-if="choice.icon"
                            :src="choice.icon"
                            :alt="`${choice.name} icon`"
                            class="h-6 w-6 rounded-sm"
                        />
                        <Wallet v-else class="h-6 w-6" />
                        <span class="font-medium">{{ choice.name }}</span>
                        <div class="ml-auto flex gap-2">
                            <Button
                                v-if="choice.evmId"
                                variant="outline"
                                size="sm"
                                :disabled="isBusy"
                                @click="web3Login.loginWithEvm(choice.evmId)"
                            >
                                EVM
                            </Button>
                            <Button
                                v-if="choice.solanaId"
                                variant="outline"
                                size="sm"
                                :disabled="isBusy"
                                @click="
                                    web3Login.loginWithSolana(choice.solanaId)
                                "
                            >
                                Solana
                            </Button>
                        </div>
                    </div>
                    <p
                        v-if="installedChoices.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No wallet detected in this browser — install one below
                        or connect a mobile wallet via WalletConnect.
                    </p>
                </div>

                <div
                    v-if="suggestedChoices.length > 0"
                    class="flex flex-col gap-2"
                >
                    <p
                        class="text-xs font-semibold text-muted-foreground uppercase"
                    >
                        Get a wallet
                    </p>
                    <div class="grid grid-cols-2 gap-2">
                        <a
                            v-for="choice in suggestedChoices"
                            :key="choice.key"
                            :href="choice.installUrl"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-2 rounded-lg border p-2 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <Wallet class="h-4 w-4" />
                            {{ choice.name }}
                            <Download class="ml-auto h-3 w-3 opacity-60" />
                        </a>
                    </div>
                </div>

                <p
                    v-if="isBusy"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner class="h-4 w-4" />
                    {{
                        web3Login.isAuthenticating.value
                            ? 'Authenticating…'
                            : 'Connecting…'
                    }}
                </p>

                <p
                    v-if="web3Login.error.value"
                    class="text-sm text-destructive"
                >
                    {{ web3Login.error.value }}
                </p>

                <div class="text-center text-sm text-muted-foreground">
                    Don't have an account?
                    <a
                        :href="register().url"
                        class="underline hover:text-foreground"
                    >
                        Sign up with email
                    </a>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
