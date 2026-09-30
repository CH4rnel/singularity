<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ExternalLink } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { useLocale } from '@/composables/useLocale';
import type { MultiWallet } from '@/composables/useMultiWallet';
import { relativeTime } from '@/lib/wallet/format';
import { signInWithWallet } from '@/lib/wallet/session';
import { fetchDao, fetchProposal, tally } from '@/lib/wallet/social';
import type { DaoSummary, ProposalSummary } from '@/lib/wallet/social';
import { walletMessages } from '@/lib/walletMessages';
import { store as daoStore } from '@/routes/dao';

/**
 * Governance, as the wallet can see it.
 *
 * Cyberia's DAO votes are weighted by a token snapshot and recorded against an
 * account on the site, so a wallet with no session cannot cast one. That is
 * stated once, plainly, and the screen does the part it genuinely can: show
 * every proposal, its real tally by voting power, and where to go to vote.
 *
 * The bar is drawn from power, not from voter count. Two small votes for and
 * one large one against is a proposal that is losing, and a bar built from
 * headcount would draw the opposite.
 */

const props = defineProps<{ wallet: MultiWallet }>();
const creating = ref(false);
const signing = ref(false);
const createError = ref<string | null>(null);
const form = useForm({ name: '', address: '' });
const signer = computed(() =>
    props.wallet.accounts.value.find(
        (account) =>
            account.chain === 'cyberia' &&
            props.wallet.activeAccount.value?.kind !== 'watch',
    ),
);
const createDao = async (): Promise<void> => {
    if (!signer.value || signing.value || form.processing) {
        return;
    }

    signing.value = true;
    createError.value = null;
    const accountId = props.wallet.activeAccountId.value;

    try {
        await signInWithWallet(signer.value.address, (message) => {
            if (accountId !== props.wallet.activeAccountId.value) {
                throw new Error(t('daoAccountChanged'));
            }

            return props.wallet.signMessage('cyberia', message);
        });

        if (accountId !== props.wallet.activeAccountId.value) {
            return;
        }

        form.post(daoStore.url(), {
            preserveScroll: true,
            onSuccess: () => {
                creating.value = false;
                form.reset();
                void load();
            },
        });
    } catch (error) {
        createError.value =
            error instanceof Error ? error.message : String(error);
    } finally {
        signing.value = false;
    }
};
watch(
    () => props.wallet.activeAccountId.value,
    () => {
        creating.value = false;
        form.reset();
        form.clearErrors();
        createError.value = null;
    },
    { flush: 'sync' },
);

const { locale, t } = useLocale(walletMessages);

const daos = ref<DaoSummary[]>([]);
const proposals = ref<ProposalSummary[]>([]);
const detail = ref<ProposalSummary | null>(null);
const loading = ref(true);
const failure = ref(false);

const openProposals = computed(
    () => proposals.value.filter((entry) => entry.status === 'open').length,
);

const seconds = (iso: string | null): number | null =>
    iso === null ? null : Math.round(Date.parse(iso) / 1000);

const load = async (): Promise<void> => {
    loading.value = true;
    failure.value = false;

    try {
        const body = await fetchDao();
        daos.value = body.daos;
        proposals.value = body.proposals;
    } catch {
        failure.value = true;
    } finally {
        loading.value = false;
    }
};

/** The list already holds the summary; this fetches the body underneath it. */
const open = async (proposal: ProposalSummary): Promise<void> => {
    detail.value = proposal;

    try {
        detail.value = await fetchProposal(proposal.id);
    } catch {
        // The summary is still on screen and still true — only the full text
        // is missing, which the detail renders as its absence.
    }
};

onMounted(load);
</script>

<template>
    <div class="cw-stack">
        <template v-if="detail">
            <button type="button" class="cw-back" @click="detail = null">
                ← {{ t('daoProposals') }}
            </button>

            <div
                style="
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin: 22px 0 10px;
                "
            >
                <span
                    class="cw-label"
                    :style="{
                        color:
                            detail.status === 'open'
                                ? 'var(--cw-ok)'
                                : 'var(--cw-muted)',
                    }"
                    >{{
                        detail.status === 'open'
                            ? t('daoStatusOpen')
                            : t('daoStatusClosed')
                    }}</span
                >
                <span class="cw-fill"></span>
                <span class="cw-label" style="color: var(--cw-faint)">{{
                    detail.endsAt
                        ? relativeTime(seconds(detail.endsAt), locale)
                        : t('daoNoDeadline')
                }}</span>
            </div>

            <h2 class="cw-title" style="margin: 0 0 10px">
                {{ detail.title }}
            </h2>
            <div class="cw-label" style="color: var(--cw-faint)">
                {{ detail.dao?.name ?? '—' }} ·
                {{ detail.author?.name ?? t('feedSomeone') }}
            </div>

            <!--
              Server-rendered from the proposal's markdown through the same
              sanitiser the site uses, so this is not a second escaping story.
            -->
            <div
                v-if="detail.descriptionHtml"
                class="cw-prose"
                style="margin-top: 18px"
                v-html="detail.descriptionHtml"
            ></div>
            <p v-else class="cw-prose" style="margin-top: 18px">
                {{ detail.summary }}
            </p>

            <div class="cw-card" style="margin-top: 22px">
                <div
                    class="cw-bar"
                    style="display: flex; height: 8px; margin-bottom: 14px"
                >
                    <span
                        :style="{
                            width: `${tally(detail.powerFor, detail.powerAgainst).for}%`,
                            background: 'var(--cw-ok)',
                        }"
                    />
                    <span
                        :style="{
                            width: `${tally(detail.powerFor, detail.powerAgainst).against}%`,
                            background: 'var(--cw-bad)',
                        }"
                    />
                </div>
                <div class="cw-row">
                    <span
                        style="
                            font: 500 13px/1 var(--cw-mono);
                            color: var(--cw-ok);
                        "
                        >{{
                            t('daoFor', {
                                percent: tally(
                                    detail.powerFor,
                                    detail.powerAgainst,
                                ).for.toFixed(1),
                            })
                        }}</span
                    >
                    <span
                        style="
                            font: 500 13px/1 var(--cw-mono);
                            color: var(--cw-bad-soft);
                        "
                        >{{
                            t('daoAgainst', {
                                percent: tally(
                                    detail.powerFor,
                                    detail.powerAgainst,
                                ).against.toFixed(1),
                            })
                        }}</span
                    >
                </div>
                <div class="cw-label" style="margin-top: 10px">
                    {{
                        t('daoCast', {
                            votes: detail.votes,
                            comments: detail.comments,
                        })
                    }}
                </div>
            </div>

            <p class="cw-note" style="margin-top: 14px">
                <span>{{ t('daoNoSession') }}</span>
            </p>

            <a
                class="cw-btn cw-btn-secondary"
                style="margin-top: 18px; text-decoration: none"
                :href="detail.url"
                target="_blank"
                rel="noopener noreferrer"
            >
                {{ t('daoOpenToVote') }}
                <ExternalLink :size="14" aria-hidden="true" />
            </a>
        </template>

        <template v-else>
            <div
                style="
                    display: flex;
                    align-items: baseline;
                    justify-content: space-between;
                    gap: 12px;
                "
            >
                <h2 class="cw-title" style="margin: 0">{{ t('dao') }}</h2>
                <span class="cw-label" style="color: var(--cw-faint)">{{
                    t('daoOpenCount', { count: openProposals })
                }}</span>
            </div>
            <p class="cw-prose" style="margin-top: 8px">{{ t('daoBody') }}</p>
            <button
                type="button"
                class="cw-btn cw-btn-secondary"
                style="margin-top: 16px"
                @click="creating = !creating"
            >
                {{ t('daoCreate') }}
            </button>
            <form
                v-if="creating"
                class="cw-card cw-stack"
                style="gap: 12px; margin-top: 12px"
                @submit.prevent="createDao"
            >
                <label
                    >{{ t('daoName')
                    }}<input v-model="form.name" class="cw-input" required
                /></label>
                <p v-if="form.errors.name" class="cw-note cw-note-bad">
                    {{ form.errors.name }}
                </p>
                <label
                    >{{ t('daoTokenAddress')
                    }}<input v-model="form.address" class="cw-input" required
                /></label>
                <p v-if="form.errors.address" class="cw-note cw-note-bad">
                    {{ form.errors.address }}
                </p>
                <p class="cw-prose">{{ t('daoCreateHint') }}</p>
                <p v-if="createError" class="cw-note cw-note-bad">
                    {{ createError }}
                </p>
                <button
                    type="submit"
                    class="cw-btn cw-btn-primary"
                    :disabled="!signer || signing || form.processing"
                >
                    {{
                        signing || form.processing
                            ? t('daoLoading')
                            : t('daoSignAndCreate')
                    }}
                </button>
            </form>

            <p
                v-if="failure"
                class="cw-note cw-note-bad"
                style="margin-top: 18px"
            >
                <span style="flex: 1">{{ t('daoUnreadable') }}</span>
                <button type="button" class="cw-back" @click="load">
                    {{ t('retry') }}
                </button>
            </p>

            <p
                v-else-if="loading"
                class="cw-label"
                style="margin-top: 18px; color: var(--cw-faint)"
            >
                {{ t('daoLoading') }}
            </p>

            <template v-else>
                <div
                    v-if="daos.length > 0"
                    style="
                        display: flex;
                        flex-wrap: wrap;
                        gap: 6px;
                        margin-top: 18px;
                    "
                >
                    <span
                        v-for="entry in daos"
                        :key="entry.id"
                        class="cw-label"
                        style="
                            border: 1px solid var(--cw-hairline);
                            padding: 7px 9px;
                            color: var(--cw-muted);
                        "
                        >{{ entry.name }} · {{ entry.proposals }}</span
                    >
                </div>

                <p
                    v-if="proposals.length === 0"
                    class="cw-prose"
                    style="margin-top: 18px"
                >
                    {{ t('daoEmpty') }}
                </p>

                <div
                    v-else
                    class="cw-stack"
                    style="gap: 10px; margin-top: 18px"
                >
                    <button
                        v-for="proposal in proposals"
                        :key="proposal.id"
                        type="button"
                        class="cw-card cw-card-button"
                        @click="open(proposal)"
                    >
                        <div
                            style="
                                display: flex;
                                align-items: center;
                                gap: 10px;
                                margin-bottom: 10px;
                            "
                        >
                            <span
                                class="cw-label"
                                :style="{
                                    color:
                                        proposal.status === 'open'
                                            ? 'var(--cw-ok)'
                                            : 'var(--cw-muted)',
                                }"
                                >{{
                                    proposal.status === 'open'
                                        ? t('daoStatusOpen')
                                        : t('daoStatusClosed')
                                }}</span
                            >
                            <span class="cw-fill"></span>
                            <span
                                class="cw-label"
                                style="color: var(--cw-faint)"
                                >{{ proposal.dao?.name ?? '—' }}</span
                            >
                        </div>
                        <div
                            style="
                                font: 500 16px/1.3 var(--cw-sans);
                                color: var(--cw-text);
                            "
                        >
                            {{ proposal.title }}
                        </div>
                        <p
                            class="cw-prose"
                            style="margin: 8px 0 14px; font-size: 14px"
                        >
                            {{ proposal.summary }}
                        </p>
                        <span class="cw-bar" style="display: flex; height: 6px">
                            <span
                                :style="{
                                    width: `${tally(proposal.powerFor, proposal.powerAgainst).for}%`,
                                    background: 'var(--cw-ok)',
                                }"
                            />
                            <span
                                :style="{
                                    width: `${tally(proposal.powerFor, proposal.powerAgainst).against}%`,
                                    background: 'var(--cw-bad)',
                                }"
                            />
                        </span>
                        <div class="cw-row" style="margin-top: 9px">
                            <span class="cw-label">{{
                                tally(proposal.powerFor, proposal.powerAgainst)
                                    .cast === 0
                                    ? t('daoNoVotes')
                                    : t('daoCastShort', {
                                          votes: proposal.votes,
                                      })
                            }}</span>
                            <span
                                class="cw-label"
                                style="color: var(--cw-faint)"
                                >{{
                                    proposal.endsAt
                                        ? relativeTime(
                                              seconds(proposal.endsAt),
                                              locale,
                                          )
                                        : t('daoNoDeadline')
                                }}</span
                            >
                        </div>
                    </button>
                </div>
            </template>
        </template>
    </div>
</template>
