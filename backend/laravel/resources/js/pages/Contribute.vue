<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ExternalLink, Github, Languages } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import PageHero from '@/components/web3/PageHero.vue';
import { useLocale } from '@/composables/useLocale';
import { useWallet } from '@/composables/useWallet';
import { contributeMessages } from '@/lib/contributeMessages';
import {
    claimReward,
    fetchContributionBoard,
    fetchContributionClaims,
    formatAmount,
    readClaimed,
} from '@/lib/contributions';
import type { ContributionBoard, ContributionClaim } from '@/lib/contributions';

const { nextTag, toggleLocale, t } = useLocale(contributeMessages);
const wallet = useWallet();

const board = ref<ContributionBoard | null>(null);
const error = ref<string | null>(null);
const claims = ref<ContributionClaim[]>([]);
const claimedOnChain = ref<Set<number>>(new Set());
const claiming = ref<number | null>(null);

const me = computed(() => board.value?.me ?? null);
const contract = computed(() => board.value?.contract.address ?? null);

const shortAddress = (address: string): string =>
    `${address.slice(0, 6)}…${address.slice(-4)}`;

const date = (iso: string): string => new Date(iso).toLocaleDateString();

const verdict = (row: {
    status: string;
    reason: string | null;
    epoch: number | null;
}): string => {
    if (row.status === 'counted') {
        return row.epoch !== null
            ? t('paidIn', { n: row.epoch })
            : t('counted');
    }

    return t(`reason_${row.reason ?? ''}`);
};

const loadClaims = async (): Promise<void> => {
    const address = me.value?.wallet;

    if (!address) {
        return;
    }

    claims.value = (await fetchContributionClaims(address)).claims;

    if (contract.value && claims.value.length > 0) {
        claimedOnChain.value = await readClaimed(
            contract.value,
            address,
            claims.value.map((claim) => claim.epoch),
        ).catch(() => new Set<number>());
    }
};

const claim = async (item: ContributionClaim): Promise<void> => {
    if (!contract.value || !me.value?.wallet) {
        return;
    }

    error.value = null;
    claiming.value = item.epoch;

    try {
        if (!wallet.isConnected.value && !(await wallet.connect())) {
            return;
        }

        await claimReward(contract.value, me.value.wallet, item);
        claimedOnChain.value = new Set([...claimedOnChain.value, item.epoch]);
    } catch (err) {
        error.value = err instanceof Error ? err.message : String(err);
    } finally {
        claiming.value = null;
    }
};

onMounted(async () => {
    try {
        board.value = await fetchContributionBoard();
        await loadClaims();
    } catch (err) {
        error.value = err instanceof Error ? err.message : String(err);
    }
});
</script>

<template>
    <Head :title="t('title')" />

    <div class="mx-auto max-w-3xl space-y-8 p-6">
        <PageHero
            :eyebrow="t('eyebrow')"
            :title="t('title')"
            :description="t('intro')"
        >
            <template #actions>
                <Button variant="ghost" size="sm" @click="toggleLocale">
                    <Languages class="mr-1 size-4" />
                    {{ nextTag }}
                </Button>
            </template>
        </PageHero>

        <p
            v-if="error"
            class="rounded-lg border border-destructive/50 p-3 text-sm text-destructive"
        >
            {{ error }}
        </p>

        <section class="space-y-2">
            <h2 class="text-lg font-semibold">{{ t('howTitle') }}</h2>
            <ol
                class="list-decimal space-y-1 pl-5 text-sm text-muted-foreground"
            >
                <li>{{ t('step1') }}</li>
                <li>{{ t('step2') }}</li>
                <li>{{ t('step3') }}</li>
                <li>{{ t('step4') }}</li>
            </ol>
        </section>

        <template v-if="board">
            <section class="rounded-lg border border-border/70 p-4">
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t('poolTitle') }}
                </p>
                <p
                    v-if="board.pool.perEpoch"
                    class="mt-1 font-mono text-lg font-bold"
                >
                    {{ Number(board.pool.perEpoch).toLocaleString() }}
                    {{ board.pool.symbol }}
                    <span class="text-xs text-muted-foreground">{{
                        t('perEpoch')
                    }}</span>
                </p>
                <p v-else class="mt-1 text-sm">{{ t('poolUndecided') }}</p>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ t('nextEpoch') }} #{{ board.nextEpoch.number }}:
                    {{ date(board.nextEpoch.startsAt) }} —
                    {{ date(board.nextEpoch.endsAt) }}.
                    {{ t('maxShare', { share: board.pool.maxShareBps / 100 }) }}
                </p>
            </section>

            <section
                class="rounded-lg border border-brand-cyan/40 bg-brand-cyan/5 p-4"
            >
                <p
                    class="text-xs tracking-widest text-muted-foreground uppercase"
                >
                    {{ t('meTitle') }}
                </p>
                <p v-if="!me" class="mt-1 text-sm">{{ t('signIn') }}</p>
                <div v-else class="mt-2 space-y-3 text-sm">
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            v-if="me.github"
                            class="flex items-center gap-1 font-mono"
                        >
                            <Github class="size-4" />
                            {{
                                t('githubAs', { login: me.github.login ?? '' })
                            }}
                        </span>
                        <Button v-else as="a" :href="me.linkUrl" size="sm">
                            <Github class="mr-1 size-4" />
                            {{ t('linkGithub') }}
                        </Button>
                        <span
                            v-if="me.wallet"
                            class="font-mono text-muted-foreground"
                        >
                            {{
                                t('walletAs', {
                                    address: shortAddress(me.wallet),
                                })
                            }}
                        </span>
                    </div>
                    <p v-if="!me.wallet" class="text-amber-500">
                        {{ t('noWalletMe') }}
                    </p>
                    <p v-if="me.pendingPoints > 0" class="font-mono">
                        {{
                            t('pending', {
                                points: me.pendingPoints.toLocaleString(),
                            })
                        }}
                    </p>

                    <div v-if="me.wallet" class="space-y-2">
                        <p class="font-semibold">{{ t('claimsTitle') }}</p>
                        <p v-if="!contract" class="text-muted-foreground">
                            {{ t('noContract') }}
                        </p>
                        <p
                            v-else-if="claims.length === 0"
                            class="text-muted-foreground"
                        >
                            {{ t('noClaims') }}
                        </p>
                        <ul v-else class="space-y-1">
                            <li
                                v-for="item in claims"
                                :key="item.epoch"
                                class="flex items-center justify-between gap-3"
                            >
                                <span>
                                    {{ t('epoch', { n: item.epoch }) }} ·
                                    <span class="font-mono"
                                        >{{
                                            formatAmount(
                                                item.amountRaw,
                                                item.decimals,
                                            )
                                        }}
                                        {{ board.pool.symbol }}</span
                                    >
                                </span>
                                <span
                                    v-if="claimedOnChain.has(item.epoch)"
                                    class="text-muted-foreground"
                                    >{{ t('claimed') }}</span
                                >
                                <Button
                                    v-else
                                    size="sm"
                                    :disabled="claiming !== null"
                                    @click="claim(item)"
                                >
                                    {{
                                        claiming === item.epoch
                                            ? t('claiming')
                                            : t('claim')
                                    }}
                                </Button>
                            </li>
                        </ul>
                    </div>

                    <div v-if="me.github" class="space-y-1">
                        <p class="font-semibold">{{ t('yourWork') }}</p>
                        <p
                            v-if="me.contributions.length === 0"
                            class="text-muted-foreground"
                        >
                            {{ t('nothingYet') }}
                        </p>
                        <ul v-else class="divide-y divide-border/60">
                            <li
                                v-for="row in me.contributions"
                                :key="`${row.repository}#${row.number}`"
                                class="flex items-center gap-3 py-1.5"
                            >
                                <a
                                    :href="row.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="min-w-0 flex-1 truncate hover:underline"
                                >
                                    {{ row.repository }}#{{ row.number }}
                                    {{ row.title }}
                                </a>
                                <span
                                    class="shrink-0 text-xs"
                                    :class="
                                        row.status === 'counted'
                                            ? 'text-brand-cyan'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ verdict(row) }}
                                </span>
                                <span
                                    class="w-14 shrink-0 text-right font-mono"
                                    >{{ row.points }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-semibold">{{ t('leadersTitle') }}</h2>
                <p
                    v-if="board.leaders.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('emptyBoard') }}
                </p>
                <ol
                    v-else
                    class="divide-y divide-border/60 rounded-lg border border-border/70"
                >
                    <li
                        v-for="(row, index) in board.leaders"
                        :key="row.login"
                        class="flex items-center gap-3 p-3"
                    >
                        <span
                            class="w-6 shrink-0 text-right font-mono text-sm text-muted-foreground"
                            >{{ index + 1 }}</span
                        >
                        <a
                            :href="`https://github.com/${row.login}`"
                            target="_blank"
                            rel="noopener"
                            class="min-w-0 flex-1 truncate font-semibold hover:underline"
                        >
                            {{ row.login }}
                        </a>
                        <span
                            v-if="!row.walletLinked"
                            class="hidden text-xs text-muted-foreground sm:inline"
                            >{{ t('noWallet') }}</span
                        >
                        <span class="shrink-0 font-mono text-sm font-bold">
                            {{ row.points.toLocaleString() }}
                            <span class="text-xs text-muted-foreground">{{
                                t('points')
                            }}</span>
                        </span>
                    </li>
                </ol>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-semibold">{{ t('rulesTitle') }}</h2>
                <ul
                    class="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                >
                    <li>
                        {{
                            t('rulePoints', {
                                pr: board.rules.points.pullRequest,
                                docs: board.rules.points.documentation,
                                issue: board.rules.points.issue,
                                mult: board.rules.points.maxSizeMultiplier,
                            })
                        }}
                    </li>
                    <li>
                        {{
                            t('ruleAge', {
                                days: board.rules.minAccountAgeDays,
                            })
                        }}
                    </li>
                    <li>
                        {{
                            t('ruleLines', {
                                lines: board.rules.minChangedLines,
                            })
                        }}
                    </li>
                    <li v-if="board.rules.refuseSelfMerged">
                        {{ t('ruleSelf') }}
                    </li>
                    <li>{{ t('ruleDay', { n: board.rules.maxPerDay }) }}</li>
                    <li>
                        {{
                            t('ruleIssues', {
                                labels: board.rules.issueLabels.join(', '),
                            })
                        }}
                    </li>
                </ul>
            </section>

            <section class="space-y-2">
                <h2 class="text-lg font-semibold">{{ t('reposTitle') }}</h2>
                <ul class="space-y-1 text-sm">
                    <li
                        v-for="repo in board.repositories"
                        :key="repo.name"
                        class="flex items-center gap-2"
                    >
                        <a
                            :href="repo.url"
                            target="_blank"
                            rel="noopener"
                            class="flex items-center gap-1 font-mono hover:underline"
                        >
                            {{ repo.name }} <ExternalLink class="size-3" />
                        </a>
                        <span
                            class="text-xs"
                            :class="
                                repo.verified
                                    ? 'text-brand-cyan'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                repo.verified ? t('verified') : t('unverified')
                            }}
                        </span>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">
                    {{
                        t('verifyHint', {
                            file: board.rules.verificationFile,
                            json: '{"chainId": 49406}',
                        })
                    }}
                </p>
            </section>
        </template>
    </div>
</template>
