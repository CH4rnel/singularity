<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Eye,
    Languages,
    Lock,
    ShieldAlert,
    Trash2,
    Wallet as WalletIcon,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import PageHero from '@/components/web3/PageHero.vue';
import { useLocale } from '@/composables/useLocale';
import { useMultiWallet } from '@/composables/useMultiWallet';
import { useWalletAuth } from '@/composables/useWalletAuth';
import { formatUnits, walletChain } from '@/lib/wallet';
import type { WalletChainId } from '@/lib/wallet';
import { walletMessages } from '@/lib/walletMessages';

/**
 * The unified multichain wallet.
 *
 * Every secret on this page is short-lived and local: the seed phrase is only
 * in memory while unlocked, only rendered inside the backup flow the user
 * asked for, and never sent anywhere. Sending is a two-step commit — build,
 * then confirm against the rendered recipient and amount — because none of
 * these chains can undo a payment.
 */

const props = defineProps<{
    solanaRpcUrl: string;
    moneroPayoutAddress: string | null;
}>();

const { locale, toggleLocale, t } = useLocale(walletMessages);

const wallet = useMultiWallet({ solana: props.solanaRpcUrl });
const walletAuth = useWalletAuth();

const password = ref('');
const passwordAgain = ref('');
const restorePhrase = ref('');
const mode = ref<'create' | 'restore'>('create');
const words = ref<12 | 24>(12);
const error = ref<string | null>(null);

/** Held only while the backup card is open, cleared the moment it closes. */
const revealedPhrase = ref<string | null>(null);
const backupPassword = ref('');

const copied = ref<string | null>(null);
const payoutSaved = ref(false);

const sendChain = ref<WalletChainId>('cyberia');
const sendTo = ref('');
const sendAmount = ref('');
const sendConfirming = ref(false);
const sendStatus = ref<'idle' | 'sending' | 'sent'>('idle');
const sendTxHash = ref<string | null>(null);

const sendableChains = computed(() =>
    wallet.chains.filter((chain) => chain.capabilities.send),
);

const monero = computed(() =>
    wallet.accounts.value.find((account) => account.chain === 'monero'),
);

const balanceLabel = (chain: WalletChainId, decimals: number): string => {
    const balance = wallet.balances.value[chain];

    if (!balance || balance.loading) {
        return '…';
    }

    if (balance.error || balance.value === null) {
        return '—';
    }

    return formatUnits(balance.value, decimals);
};

const failWith = (e: unknown): void => {
    error.value = e instanceof Error ? e.message : String(e);
};

async function createWallet() {
    error.value = null;

    if (password.value !== passwordAgain.value) {
        error.value = t('passwordMismatch');

        return;
    }

    try {
        revealedPhrase.value = await wallet.create(password.value, words.value);
        password.value = '';
        passwordAgain.value = '';
        await wallet.refreshBalances();
    } catch (e) {
        failWith(e);
    }
}

async function restoreWallet() {
    error.value = null;

    if (password.value !== passwordAgain.value) {
        error.value = t('passwordMismatch');

        return;
    }

    try {
        await wallet.restore(restorePhrase.value, password.value);
        restorePhrase.value = '';
        password.value = '';
        passwordAgain.value = '';
        await wallet.refreshBalances();
    } catch (e) {
        failWith(e);
    }
}

async function unlockWallet() {
    error.value = null;

    try {
        await wallet.unlock(password.value);
        password.value = '';
        await wallet.refreshBalances();
    } catch (e) {
        failWith(e);
    }
}

async function revealBackup() {
    error.value = null;

    try {
        revealedPhrase.value = await wallet.reveal(backupPassword.value);
        backupPassword.value = '';
    } catch (e) {
        failWith(e);
    }
}

function hideBackup() {
    revealedPhrase.value = null;
}

function forgetWallet() {
    if (!window.confirm(t('forgetConfirm'))) {
        return;
    }

    wallet.forget();
    revealedPhrase.value = null;
}

async function copyAddress(address: string) {
    await navigator.clipboard.writeText(address);
    copied.value = address;
    window.setTimeout(() => {
        copied.value = copied.value === address ? null : copied.value;
    }, 1500);
}

async function useForPayouts(address: string) {
    error.value = null;

    try {
        await walletAuth.attachMoneroWallet(address);
        payoutSaved.value = true;
    } catch (e) {
        failWith(e);
    }
}

async function confirmSend() {
    error.value = null;
    sendStatus.value = 'sending';

    try {
        sendTxHash.value = await wallet.send(
            sendChain.value,
            sendTo.value,
            sendAmount.value,
        );
        sendStatus.value = 'sent';
        sendConfirming.value = false;
        sendTo.value = '';
        sendAmount.value = '';
        await wallet.refreshBalances();
    } catch (e) {
        sendStatus.value = 'idle';
        failWith(e);
    }
}

const sendTxUrl = computed(() =>
    sendTxHash.value
        ? walletChain(sendChain.value).explorerTxUrl(sendTxHash.value)
        : null,
);

const canReview = computed(
    () => sendTo.value.trim().length > 0 && sendAmount.value.trim().length > 0,
);
</script>

<template>
    <Head :title="t('wallet')" />

    <div class="mx-auto max-w-3xl space-y-8 p-6">
        <PageHero
            :eyebrow="t('eyebrow')"
            :title="t('wallet')"
            :description="t('intro')"
        >
            <template #actions>
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="mr-1 size-4" />
                    {{ locale === 'ru' ? 'EN' : 'RU' }}
                </Button>
                <Button
                    v-if="wallet.unlocked.value"
                    variant="outline"
                    size="sm"
                    @click="wallet.lock()"
                >
                    <Lock class="mr-1 size-4" />
                    {{ t('lock') }}
                </Button>
            </template>
        </PageHero>

        <p
            v-if="error"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <!-- Backup: the only place the phrase is ever rendered. -->
        <section
            v-if="revealedPhrase"
            class="space-y-3 rounded-lg border border-amber-500/50 bg-amber-500/5 p-4"
        >
            <div class="flex items-center gap-2">
                <ShieldAlert class="size-4 text-amber-500" />
                <h2 class="text-sm font-semibold">{{ t('backupTitle') }}</h2>
            </div>
            <p class="text-xs text-muted-foreground">{{ t('backupBody') }}</p>
            <p
                class="grid grid-cols-2 gap-1 rounded-md border border-border/70 bg-background p-3 font-mono text-sm sm:grid-cols-3"
            >
                <span
                    v-for="(word, index) in revealedPhrase.split(' ')"
                    :key="index"
                >
                    <span class="mr-1 text-muted-foreground">{{
                        index + 1
                    }}</span>
                    {{ word }}
                </span>
            </p>
            <Button size="sm" @click="hideBackup">
                {{ t('backupConfirm') }}
            </Button>
        </section>

        <!-- No wallet on this device yet: create or restore. -->
        <section v-if="!wallet.exists.value" class="space-y-4">
            <div class="flex gap-2">
                <Button
                    :variant="mode === 'create' ? 'default' : 'outline'"
                    size="sm"
                    @click="mode = 'create'"
                >
                    {{ t('create') }}
                </Button>
                <Button
                    :variant="mode === 'restore' ? 'default' : 'outline'"
                    size="sm"
                    @click="mode = 'restore'"
                >
                    {{ t('restore') }}
                </Button>
            </div>

            <form
                class="space-y-3 rounded-lg border border-border/70 bg-card p-4"
                @submit.prevent="
                    mode === 'create' ? createWallet() : restoreWallet()
                "
            >
                <p class="text-xs text-muted-foreground">
                    {{ mode === 'create' ? t('createHint') : t('restoreHint') }}
                </p>

                <div v-if="mode === 'create'" class="flex gap-2">
                    <Button
                        type="button"
                        :variant="words === 12 ? 'secondary' : 'outline'"
                        size="sm"
                        @click="words = 12"
                    >
                        {{ t('words12') }}
                    </Button>
                    <Button
                        type="button"
                        :variant="words === 24 ? 'secondary' : 'outline'"
                        size="sm"
                        @click="words = 24"
                    >
                        {{ t('words24') }}
                    </Button>
                </div>

                <Input
                    v-if="mode === 'restore'"
                    v-model="restorePhrase"
                    type="password"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="t('seedPhrase')"
                    class="font-mono text-xs"
                />
                <Input
                    v-model="password"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="t('password')"
                />
                <Input
                    v-model="passwordAgain"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="t('passwordAgain')"
                />
                <p class="text-xs text-muted-foreground">
                    {{ t('passwordHint') }}
                </p>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="wallet.busy.value || password.length < 8"
                >
                    <WalletIcon class="mr-1 size-4" />
                    {{ mode === 'create' ? t('create') : t('restore') }}
                </Button>
            </form>
        </section>

        <!-- Vault present but sealed. -->
        <section
            v-else-if="!wallet.unlocked.value"
            class="space-y-3 rounded-lg border border-border/70 bg-card p-4"
        >
            <div class="flex items-center gap-2">
                <Lock class="size-4 text-brand-cyan" />
                <h2 class="text-sm font-semibold">{{ t('locked') }}</h2>
            </div>
            <p class="text-xs text-muted-foreground">{{ t('unlockHint') }}</p>
            <form
                class="flex flex-col gap-2 sm:flex-row"
                @submit.prevent="unlockWallet"
            >
                <Input
                    v-model="password"
                    type="password"
                    autocomplete="current-password"
                    :placeholder="t('password')"
                    class="sm:flex-1"
                />
                <Button
                    type="submit"
                    size="sm"
                    :disabled="wallet.busy.value || password.length === 0"
                >
                    {{ t('unlock') }}
                </Button>
            </form>
            <Button
                variant="ghost"
                size="sm"
                class="text-destructive"
                @click="forgetWallet"
            >
                <Trash2 class="mr-1 size-4" />
                {{ t('forget') }}
            </Button>
        </section>

        <!-- Unlocked: accounts, receive, send, backup. -->
        <template v-else>
            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h2
                        class="text-sm font-semibold tracking-widest text-muted-foreground uppercase"
                    >
                        {{ t('accounts') }}
                    </h2>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="wallet.refreshBalances()"
                    >
                        {{ t('refresh') }}
                    </Button>
                </div>

                <article
                    v-for="account in wallet.accounts.value"
                    :key="account.chain"
                    class="space-y-2 rounded-lg border border-border/70 bg-card p-4"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold">{{
                            account.label
                        }}</span>
                        <Badge
                            variant="outline"
                            class="text-[10px] tracking-widest uppercase"
                        >
                            {{ account.symbol }}
                        </Badge>
                        <Badge
                            v-if="!account.capabilities.send"
                            variant="secondary"
                            class="text-[10px]"
                        >
                            {{ t('noSend') }}
                        </Badge>
                        <span class="ml-auto font-mono text-sm">
                            {{
                                account.capabilities.balance
                                    ? balanceLabel(
                                          account.chain,
                                          account.decimals,
                                      )
                                    : t('noBalanceHere')
                            }}
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <code
                            class="min-w-0 flex-1 truncate rounded bg-muted px-2 py-1 font-mono text-xs"
                            >{{ account.address }}</code
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="copyAddress(account.address)"
                        >
                            <Check
                                v-if="copied === account.address"
                                class="size-4"
                            />
                            <Copy v-else class="size-4" />
                        </Button>
                        <a
                            v-if="account.explorerUrl"
                            :href="account.explorerUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="text-xs text-brand-cyan underline"
                        >
                            {{ t('explorer') }}
                        </a>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ t('path') }}:
                        <code class="font-mono">{{ account.path }}</code>
                        · {{ account.curve }}
                    </p>
                    <p
                        v-if="account.note"
                        class="text-xs text-muted-foreground"
                    >
                        {{ account.note }}
                    </p>
                </article>

                <p class="text-xs text-muted-foreground">
                    {{ t('receiveHint') }}
                </p>
            </section>

            <!-- Monero ties back to the payout address the bridge already uses. -->
            <section
                v-if="monero"
                class="space-y-2 rounded-lg border border-border/70 bg-card p-4"
            >
                <h2 class="text-sm font-semibold">{{ t('useForPayouts') }}</h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('useForPayoutsHint') }}
                </p>
                <Button
                    v-if="
                        !payoutSaved &&
                        props.moneroPayoutAddress !== monero.address
                    "
                    variant="outline"
                    size="sm"
                    @click="useForPayouts(monero.address)"
                >
                    {{ t('useForPayouts') }}
                </Button>
                <p v-else class="text-xs text-brand-cyan">
                    {{ t('useForPayoutsDone') }}
                </p>
            </section>

            <!-- Send: build, then confirm. -->
            <section
                class="space-y-3 rounded-lg border border-border/70 bg-card p-4"
            >
                <h2 class="text-sm font-semibold">{{ t('send') }}</h2>

                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="chain in sendableChains"
                        :key="chain.id"
                        :variant="
                            sendChain === chain.id ? 'secondary' : 'outline'
                        "
                        size="sm"
                        @click="
                            sendChain = chain.id;
                            sendConfirming = false;
                        "
                    >
                        {{ chain.label }}
                    </Button>
                </div>

                <Input
                    v-model="sendTo"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="t('sendTo')"
                    class="font-mono text-xs"
                    :disabled="sendConfirming"
                />
                <Input
                    v-model="sendAmount"
                    type="text"
                    inputmode="decimal"
                    :placeholder="t('amount')"
                    :disabled="sendConfirming"
                />

                <div
                    v-if="sendConfirming"
                    class="space-y-2 rounded-md border border-amber-500/50 bg-amber-500/5 p-3"
                >
                    <p class="text-sm font-semibold">{{ t('confirmTitle') }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ t('confirmBody') }}
                    </p>
                    <p class="font-mono text-xs break-all">
                        {{ sendAmount }}
                        {{ walletChain(sendChain).symbol }} →
                        {{ sendTo }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            :disabled="sendStatus === 'sending'"
                            @click="confirmSend"
                        >
                            {{
                                sendStatus === 'sending'
                                    ? t('sending')
                                    : t('confirmSend')
                            }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="sendStatus === 'sending'"
                            @click="sendConfirming = false"
                        >
                            {{ t('cancel') }}
                        </Button>
                    </div>
                </div>
                <Button
                    v-else
                    size="sm"
                    :disabled="!canReview"
                    @click="
                        sendStatus = 'idle';
                        sendConfirming = true;
                    "
                >
                    {{ t('review') }}
                </Button>

                <p v-if="sendStatus === 'sent'" class="text-xs text-brand-cyan">
                    {{ t('sent') }}
                    <a
                        v-if="sendTxUrl"
                        :href="sendTxUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline"
                    >
                        {{ t('viewTx') }}
                    </a>
                </p>
            </section>

            <!-- Backup and removal, both password-gated. -->
            <section
                class="space-y-3 rounded-lg border border-border/70 bg-card p-4"
            >
                <h2 class="text-sm font-semibold">{{ t('backup') }}</h2>
                <p class="text-xs text-muted-foreground">
                    {{ t('backupHint') }}
                </p>
                <form
                    class="flex flex-col gap-2 sm:flex-row"
                    @submit.prevent="revealBackup"
                >
                    <Input
                        v-model="backupPassword"
                        type="password"
                        autocomplete="current-password"
                        :placeholder="t('password')"
                        class="sm:flex-1"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="backupPassword.length === 0"
                    >
                        <Eye class="mr-1 size-4" />
                        {{ t('backup') }}
                    </Button>
                </form>
                <p class="text-xs text-muted-foreground">
                    {{ t('forgetHint') }}
                </p>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="forgetWallet"
                >
                    <Trash2 class="mr-1 size-4" />
                    {{ t('forget') }}
                </Button>
            </section>
        </template>
    </div>
</template>
