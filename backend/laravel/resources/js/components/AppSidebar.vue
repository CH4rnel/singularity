<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    FolderGit2,
    LayoutGrid,
    Link as LinkIcon,
    Folder,
    Vote,
    Wallet,
} from 'lucide-vue-next';
import { computed, onMounted } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import TeamSwitcher from '@/components/TeamSwitcher.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { useWallet } from '@/composables/useWallet';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';
import type { NavItem } from '@/types';

const page = usePage();
const wallet = useWallet();
const solanaWallet = useSolanaWallet();
const walletAuth = useWalletAuth();

// Restore wallet state from saved user addresses on mount
onMounted(() => {
    const user = page.props.auth?.user as
        | {
              wallet_address?: string | null;
              solana_wallet_address?: string | null;
          }
        | undefined;
    wallet.restore(user?.wallet_address);
    solanaWallet.restore(user?.solana_wallet_address);
});

const dashboardUrl = computed(() =>
    page.props.currentTeam ? dashboard(page.props.currentTeam.slug).url : '/',
);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Links',
        href: '/links',
        icon: LinkIcon,
    },
    {
        title: 'Categories',
        href: '/categories',
        icon: Folder,
    },
    {
        title: 'DAO',
        href: '/dao',
        icon: Vote,
    },
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];

const handleWalletConnect = async () => {
    const address = await wallet.connect();
    if (address) {
        try {
            const { nonce } = await walletAuth.generateNonce(address);
            const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
            const signature = await wallet.signMessage(message);

            if (signature) {
                const response = await walletAuth.verifySignature(
                    address,
                    signature,
                );
                router.post('/login/web3', { token: response.token });
            }
        } catch {
            // Error handled by composable
        }
    }
};

const handleSolanaConnect = async () => {
    const address = await solanaWallet.connect();
    if (address) {
        try {
            const { nonce } = await walletAuth.generateSolanaNonce(address);
            const message = `Sign this message to authenticate with your wallet. Nonce: ${nonce}`;
            const signature = await solanaWallet.signMessage(message);

            if (signature) {
                const response = await walletAuth.verifySolanaSignature(
                    address,
                    signature,
                );
                router.post('/login/web3', { token: response.token });
            }
        } catch {
            // Error handled by composable
        }
    }
};

const formatCyberBalance = (balance: string): string => {
    const num = parseFloat(balance);
    if (num === 0) return '0';
    if (num < 0.0001) return '<0.0001';
    if (num < 1) return num.toFixed(4);
    if (num < 1000) return num.toFixed(2);
    return num.toLocaleString('en-US', { maximumFractionDigits: 0 });
};
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <SidebarMenu>
                <SidebarMenuItem>
                    <TeamSwitcher />
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        class="flex-col items-start gap-1"
                        :disabled="wallet.isConnecting.value"
                        @click="handleWalletConnect"
                    >
                        <div class="flex w-full items-center gap-2">
                            <Wallet
                                v-if="!wallet.isConnecting.value"
                                class="h-4 w-4"
                            />
                            <span
                                v-if="wallet.isConnected.value"
                                class="font-mono text-xs"
                            >
                                EVM:
                                {{
                                    wallet.formatAddress(wallet.address.value!)
                                }}
                            </span>
                            <span v-else>
                                {{
                                    wallet.isConnecting.value
                                        ? 'Connecting...'
                                        : 'Connect EVM'
                                }}
                            </span>
                        </div>
                        <span
                            v-if="
                                wallet.isConnected.value &&
                                wallet.cyberBalance.value
                            "
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                formatCyberBalance(
                                    wallet.cyberBalance.value.formatted,
                                )
                            }}
                            CYBER
                        </span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        class="flex-col items-start gap-1"
                        :disabled="solanaWallet.isConnecting.value"
                        @click="handleSolanaConnect"
                    >
                        <div class="flex w-full items-center gap-2">
                            <Wallet
                                v-if="!solanaWallet.isConnecting.value"
                                class="h-4 w-4"
                            />
                            <span
                                v-if="solanaWallet.isConnected.value"
                                class="font-mono text-xs"
                            >
                                SOL:
                                {{
                                    solanaWallet.formatAddress(
                                        solanaWallet.address.value!,
                                    )
                                }}
                            </span>
                            <span v-else>
                                {{
                                    solanaWallet.isConnecting.value
                                        ? 'Connecting...'
                                        : 'Connect Solana'
                                }}
                            </span>
                        </div>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
