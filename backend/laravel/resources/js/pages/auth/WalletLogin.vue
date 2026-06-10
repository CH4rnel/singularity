<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Wallet, Shield, Zap } from 'lucide-vue-next';
import { register } from '@/routes';
import { useWallet } from '@/composables/useWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';

defineOptions({
    layout: {
        title: 'Sign in with your wallet',
        description: 'Connect your Web3 wallet to authenticate',
    },
});

const isLoading = ref(false);
const error = ref<string | null>(null);

const wallet = useWallet();
const walletAuth = useWalletAuth();

const authenticate = async (address: string) => {
    isLoading.value = true;
    error.value = null;

    try {
        const { nonce } = await walletAuth.generateNonce(address);

        const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
        const signature = await wallet.signMessage(message);

        if (!signature) {
            error.value = 'Failed to sign message. Please try again.';
            isLoading.value = false;
            return;
        }

        const response = await walletAuth.verifySignature(address, signature);

        router.post(
            '/login/web3',
            {
                token: response.token,
            },
            {
                onFinish: () => {
                    isLoading.value = false;
                },
                onError: (err: Record<string, string>) => {
                    error.value = err.message || 'Authentication failed';
                },
            },
        );
    } catch (err) {
        error.value =
            err instanceof Error
                ? err.message
                : 'Authentication failed. Please try again.';
        isLoading.value = false;
    }
};

const handleConnect = async () => {
    const address = await wallet.connect();
    if (address) {
        await authenticate(address);
    } else if (wallet.error.value) {
        error.value = wallet.error.value;
    }
};

onMounted(() => {
    if (wallet.isMetaMaskInstalled() && !wallet.isConnected.value) {
        wallet.connect().then((address) => {
            if (address) {
                authenticate(address);
            }
        });
    }
});
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
                    Connect your Web3 wallet to access your account
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

                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <span class="w-full border-t"></span>
                    </div>
                    <div class="relative flex justify-center text-xs uppercase">
                        <span class="bg-background px-2 text-muted-foreground"
                            >Connect below</span
                        >
                    </div>
                </div>

                <div class="flex flex-col items-center">
                    <Button
                        variant="default"
                        size="lg"
                        class="w-full gap-2"
                        :disabled="isLoading || wallet.isConnecting.value"
                        @click="handleConnect"
                    >
                        <Spinner
                            v-if="wallet.isConnecting.value"
                            class="h-4 w-4"
                        />
                        <Wallet v-else class="h-4 w-4" />
                        {{
                            wallet.isConnecting.value
                                ? 'Connecting...'
                                : 'Connect Wallet'
                        }}
                    </Button>

                    <p
                        v-if="isLoading"
                        class="mt-4 flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <Spinner class="h-4 w-4" />
                        Authenticating...
                    </p>

                    <p v-if="error" class="mt-4 text-sm text-destructive">
                        {{ error }}
                    </p>
                </div>

                <div class="text-center text-sm text-muted-foreground">
                    Don't have an account?
                    <a
                        :href="register().url()"
                        class="underline hover:text-foreground"
                    >
                        Sign up with email
                    </a>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
