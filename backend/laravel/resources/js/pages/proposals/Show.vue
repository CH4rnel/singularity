<script setup lang="ts">
import { Head, Link as InertiaLink } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { computed } from 'vue';
import CommentThread from '@/components/dao/CommentThread.vue';
import MarkdownContent from '@/components/dao/MarkdownContent.vue';
import ReactionBar from '@/components/dao/ReactionBar.vue';
import VotePanel from '@/components/dao/VotePanel.vue';
import { Badge } from '@/components/ui/badge';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { show as daoShow } from '@/routes/dao';
import { show as userShow } from '@/routes/users';
import type { Proposal, ProposalComment, ProposalVote } from '@/types';
import type { Paginated } from '@/types/pagination';

type Props = {
    proposal: Proposal;
    comments: Paginated<ProposalComment>;
    userVote: ProposalVote | null;
};

const props = defineProps<Props>();

const createdAgo = useTimeAgo(computed(() => props.proposal.created_at));
</script>

<template>
    <Head :title="props.proposal.title" />

    <div class="mx-auto flex max-w-5xl flex-col space-y-6 px-4 py-8">
        <!-- Proposal header -->
        <header>
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <InertiaLink
                    v-if="props.proposal.dao"
                    :href="daoShow(props.proposal.dao.id).url"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    {{ props.proposal.dao.name }}
                </InertiaLink>
                <span class="text-sm text-muted-foreground">/</span>
                <Badge
                    :variant="
                        props.proposal.status === 'open'
                            ? 'default'
                            : 'secondary'
                    "
                >
                    {{ props.proposal.status }}
                </Badge>
            </div>

            <h1 class="text-2xl font-extrabold tracking-tight">
                {{ props.proposal.title }}
            </h1>

            <div class="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                <InertiaLink
                    :href="userShow(props.proposal.user_id).url"
                    class="flex items-center gap-2 hover:underline"
                >
                    <WalletAvatar
                        :seed="props.proposal.user?.wallet_address"
                        :name="props.proposal.user?.name"
                        size="sm"
                    />
                    {{ props.proposal.user?.name || 'Unknown' }}
                </InertiaLink>
                <span>·</span>
                <span>{{ createdAgo }}</span>
            </div>

            <MarkdownContent
                class="mt-4"
                :html="props.proposal.description_html"
            />

            <div class="mt-4">
                <ReactionBar
                    reactable-type="proposal"
                    :reactable-id="props.proposal.id"
                    :reactions="props.proposal.reactions"
                />
            </div>
        </header>

        <VotePanel :proposal="props.proposal" :user-vote="props.userVote" />

        <CommentThread
            :proposal-id="props.proposal.id"
            :comments="props.comments"
        />
    </div>
</template>
