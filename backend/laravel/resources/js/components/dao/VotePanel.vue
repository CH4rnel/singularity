<script setup lang="ts">
import { Link as InertiaLink, router, usePage } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import {
    Loader2,
    ThumbsDown,
    ThumbsUp,
    Timer,
    Users,
    Vote,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { useSolanaWallet } from '@/composables/useSolanaWallet';
import { useWallet } from '@/composables/useWallet';
import { profileUrl } from '@/lib/profileUrl';
import { store as voteStore } from '@/routes/proposals/votes';
import type { Proposal, ProposalVote, User } from '@/types';

const props = defineProps<{
    proposal: Proposal;
    userVote: ProposalVote | null;
}>();

const page = usePage();
const wallet = useWallet();
const solanaWallet = useSolanaWallet();

// Vote with the Solana (e.g. Phantom) wallet instead of the default EVM one.
// Starts pre-selected when the user has no EVM address to fall back to.
const useSolanaForVote = ref(false);

const authUser = computed(() => page.props.auth?.user as User | undefined);
const isAuthenticated = computed(() => !!authUser.value);
const evmAddress = computed(
    () => wallet.address.value || authUser.value?.wallet_address || null,
);
const solanaAddress = computed(
    () =>
        solanaWallet.address.value ||
        authUser.value?.solana_wallet_address ||
        null,
);
const canVoteWithSolana = computed(
    () => !!solanaAddress.value || solanaWallet.isSolanaWalletInstalled(),
);
const canVoteWithEvm = computed(
    () => !!evmAddress.value || wallet.isEvmProviderInstalled(),
);
const walletAddress = computed(() =>
    useSolanaForVote.value
        ? solanaAddress.value
        : (evmAddress.value ?? solanaAddress.value),
);
const hasWallet = computed(() => !!walletAddress.value);
const isVoting = ref(false);
const voteError = ref<string | null>(null);

function toggleVoteWallet(): void {
    useSolanaForVote.value = !useSolanaForVote.value;
    voteError.value = null;
}

const isOpen = computed(() => props.proposal.status === 'open');
const deadlineAgo = useTimeAgo(computed(() => props.proposal.ends_at ?? ''));

const powerFor = computed(() => parseFloat(props.proposal.power_for || '0'));
const powerAgainst = computed(() =>
    parseFloat(props.proposal.power_against || '0'),
);
const totalPower = computed(() => powerFor.value + powerAgainst.value);

async function castVote(support: boolean) {
    voteError.value = null;

    if (!isAuthenticated.value) {
        voteError.value = 'Sign in with your wallet to vote.';

        return;
    }

    isVoting.value = true;

    try {
        if (!walletAddress.value) {
            const connected = useSolanaForVote.value
                ? await solanaWallet.connect()
                : await wallet.connect();

            if (!connected) {
                voteError.value = useSolanaForVote.value
                    ? (solanaWallet.error.value ??
                      'No Phantom (Solana) wallet connected.')
                    : (wallet.error.value ??
                      'No wallet connected. Please connect your wallet first.');

                return;
            }
        }

        router.post(
            voteStore(props.proposal.id).url,
            {
                wallet_address: walletAddress.value!,
                support,
            },
            { preserveScroll: true },
        );
    } finally {
        isVoting.value = false;
    }
}

function formatPower(power: number): string {
    if (power === 0) {
        return '0';
    }

    if (power < 0.0001) {
        return power.toExponential(2);
    }

    const truncated = Math.trunc(power * 10000) / 10000;

    if (truncated < 1) {
        return truncated.toString();
    }

    if (truncated < 1000) {
        return truncated.toFixed(4).replace(/\.?0+$/, '');
    }

    return truncated.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function formatAddress(address: string): string {
    if (address.length <= 12) {
        return address;
    }

    return address.slice(0, 6) + '...' + address.slice(-4);
}
</script>

<template>
    <section class="space-y-5 rounded-lg border border-border/70 bg-card p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-lg font-medium">
                <Vote class="h-5 w-5 text-brand-cyan" /> Voting
            </h3>
            <p
                v-if="proposal.ends_at"
                class="flex items-center gap-1 text-sm text-muted-foreground"
            >
                <Timer class="h-4 w-4" />
                <template v-if="isOpen">ends {{ deadlineAgo }}</template>
                <template v-else>ended {{ deadlineAgo }}</template>
            </p>
        </div>

        <!-- Results bars -->
        <div class="space-y-3">
            <div class="space-y-1">
                <div class="flex justify-between text-sm">
                    <span class="flex items-center gap-1 font-medium">
                        <ThumbsUp class="h-4 w-4 text-green-500" /> For
                    </span>
                    <span class="font-mono text-muted-foreground">
                        {{ formatPower(powerFor) }}
                    </span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-green-500 transition-all duration-500"
                        :style="{
                            width:
                                totalPower > 0
                                    ? `${(powerFor / totalPower) * 100}%`
                                    : '0%',
                        }"
                    />
                </div>
            </div>

            <div class="space-y-1">
                <div class="flex justify-between text-sm">
                    <span class="flex items-center gap-1 font-medium">
                        <ThumbsDown class="h-4 w-4 text-red-500" /> Against
                    </span>
                    <span class="font-mono text-muted-foreground">
                        {{ formatPower(powerAgainst) }}
                    </span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-red-500 transition-all duration-500"
                        :style="{
                            width:
                                totalPower > 0
                                    ? `${(powerAgainst / totalPower) * 100}%`
                                    : '0%',
                        }"
                    />
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div v-if="isAuthenticated && isOpen" class="space-y-3">
            <div class="flex gap-2">
                <Button
                    :variant="
                        props.userVote?.support === true ? 'default' : 'outline'
                    "
                    :disabled="isVoting"
                    @click="castVote(true)"
                >
                    <Loader2
                        v-if="isVoting"
                        class="mr-1 h-4 w-4 animate-spin"
                    />
                    <ThumbsUp v-else class="mr-1 h-4 w-4" />
                    Vote For
                </Button>
                <Button
                    :variant="
                        props.userVote?.support === false
                            ? 'destructive'
                            : 'outline'
                    "
                    :disabled="isVoting"
                    @click="castVote(false)"
                >
                    <Loader2
                        v-if="isVoting"
                        class="mr-1 h-4 w-4 animate-spin"
                    />
                    <ThumbsDown v-else class="mr-1 h-4 w-4" />
                    Vote Against
                </Button>
            </div>

            <p
                v-if="!props.userVote && !useSolanaForVote && canVoteWithSolana"
                class="text-xs text-muted-foreground"
            >
                <button
                    type="button"
                    class="underline hover:text-foreground"
                    @click="toggleVoteWallet"
                >
                    Vote with Phantom (Solana) instead
                </button>
            </p>
            <p
                v-else-if="!props.userVote && useSolanaForVote && canVoteWithEvm"
                class="text-xs text-muted-foreground"
            >
                <button
                    type="button"
                    class="underline hover:text-foreground"
                    @click="toggleVoteWallet"
                >
                    Vote with EVM wallet instead
                </button>
            </p>

            <p v-if="voteError" class="text-sm text-destructive">
                {{ voteError }}
            </p>

            <p
                v-if="props.userVote"
                class="flex items-center gap-1 text-sm text-muted-foreground"
            >
                <Wallet class="h-3.5 w-3.5" />
                You voted
                <strong>{{ props.userVote.support ? 'for' : 'against' }}</strong>
                with
                {{ formatPower(parseFloat(props.userVote.voting_power)) }}
                voting power from
                {{ formatAddress(props.userVote.wallet_address) }}
            </p>

            <p
                v-if="!hasWallet && !props.userVote"
                class="flex items-center gap-1 text-sm text-muted-foreground"
            >
                <Wallet class="h-3.5 w-3.5" />
                Connect your wallet (EVM or Phantom/Solana) to vote. Your
                token balance at the DAO contract = your voting power.
            </p>
        </div>

        <div v-else-if="!isOpen" class="text-sm text-muted-foreground">
            Voting is closed. The discussion below stays open.
        </div>

        <div v-else class="text-sm text-muted-foreground">
            Voting is available after wallet sign in.
            <InertiaLink href="/wallet-login" class="font-medium underline">
                Sign in
            </InertiaLink>
        </div>

        <!-- Voters -->
        <div v-if="proposal.votes && proposal.votes.length > 0">
            <h4 class="mb-2 flex items-center gap-1 text-sm font-medium">
                <Users class="h-4 w-4" /> Voters ({{ proposal.votes.length }})
            </h4>
            <div class="space-y-1">
                <div
                    v-for="vote in proposal.votes"
                    :key="vote.id"
                    class="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-accent/50"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <ThumbsUp
                            v-if="vote.support"
                            class="h-3.5 w-3.5 shrink-0 text-green-500"
                        />
                        <ThumbsDown
                            v-else
                            class="h-3.5 w-3.5 shrink-0 text-red-500"
                        />
                        <WalletAvatar
                            :seed="vote.wallet_address"
                            :name="vote.user?.name"
                            :src="vote.user?.avatar"
                            size="sm"
                        />
                        <InertiaLink
                            :href="profileUrl(vote.user, vote.user_id)"
                            class="truncate hover:underline"
                        >
                            {{ vote.user?.name || 'Unknown' }}
                        </InertiaLink>
                        <Badge variant="outline" class="font-mono text-xs">
                            {{ formatAddress(vote.wallet_address) }}
                        </Badge>
                    </div>
                    <span class="ml-2 shrink-0 font-mono text-muted-foreground">
                        {{ formatPower(parseFloat(vote.voting_power)) }}
                    </span>
                </div>
            </div>
        </div>
    </section>
</template>
