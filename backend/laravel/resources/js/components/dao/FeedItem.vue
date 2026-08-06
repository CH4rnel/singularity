<script setup lang="ts">
import { Link as InertiaLink } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { MessageSquare, ScrollText, Vote } from 'lucide-vue-next';
import { computed } from 'vue';
import MarkdownContent from '@/components/dao/MarkdownContent.vue';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { profileUrl } from '@/lib/profileUrl';
import { show as proposalShow } from '@/routes/proposals';
import type { Activity, Proposal, ProposalComment, ProposalVote } from '@/types';

const props = defineProps<{
    activity: Activity;
}>();

const timeAgo = useTimeAgo(computed(() => props.activity.created_at));

const meta = computed(() => {
    const subject = props.activity.subject;

    switch (props.activity.type) {
        case 'proposal.created': {
            const proposal = subject as Proposal | null;

            return {
                icon: ScrollText,
                iconClass: 'text-brand-cyan',
                action: 'created a proposal',
                title: proposal?.title ?? '[deleted]',
                url: proposal ? proposalShow(proposal.id).url : null,
                bodyHtml: proposal?.description_html ?? null,
            };
        }
        case 'vote.cast': {
            const vote = subject as ProposalVote | null;

            return {
                icon: Vote,
                iconClass: vote?.support ? 'text-green-500' : 'text-red-500',
                action: vote
                    ? `voted ${vote.support ? 'FOR' : 'AGAINST'}`
                    : 'voted on',
                title: vote?.proposal?.title ?? '[deleted]',
                url: vote?.proposal ? proposalShow(vote.proposal.id).url : null,
                bodyHtml: null,
            };
        }
        case 'comment.posted': {
            const comment = subject as ProposalComment | null;

            return {
                icon: MessageSquare,
                iconClass: 'text-brand-magenta',
                action: 'commented on',
                title: comment?.proposal?.title ?? '[deleted]',
                url: comment?.proposal
                    ? proposalShow(comment.proposal.id).url
                    : null,
                bodyHtml: comment?.body_html ?? null,
            };
        }
        default:
            return {
                icon: ScrollText,
                iconClass: 'text-muted-foreground',
                action: 'did something',
                title: '[unknown]',
                url: null,
                bodyHtml: null,
            };
    }
});

const actorName = computed(
    () =>
        props.activity.user?.name ||
        (props.activity.user?.wallet_address
            ? props.activity.user.wallet_address.slice(0, 6) +
              '…' +
              props.activity.user.wallet_address.slice(-4)
            : 'Unknown'),
);
</script>

<template>
    <div class="flex gap-3 rounded-lg border border-border/70 bg-card p-4">
        <InertiaLink
            :href="profileUrl(activity.user, activity.user_id)"
            class="shrink-0"
        >
            <WalletAvatar
                :seed="activity.user?.wallet_address"
                :name="activity.user?.name"
                :src="activity.user?.avatar"
            />
        </InertiaLink>

        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-baseline gap-x-1 text-sm">
                <InertiaLink
                    :href="profileUrl(activity.user, activity.user_id)"
                    class="font-semibold hover:underline"
                >
                    {{ actorName }}
                </InertiaLink>
                <span class="text-muted-foreground"> {{ meta.action }} </span>
                <InertiaLink
                    v-if="meta.url"
                    :href="meta.url"
                    class="font-medium hover:underline"
                >
                    {{ meta.title }}
                </InertiaLink>
                <span v-else class="text-muted-foreground italic">
                    {{ meta.title }}
                </span>
            </p>

            <MarkdownContent
                v-if="meta.bodyHtml"
                class="mt-1 line-clamp-2"
                :html="meta.bodyHtml"
                compact
            />

            <p class="mt-1 flex items-center gap-2 text-xs text-muted-foreground/70">
                <component :is="meta.icon" class="h-3.5 w-3.5" :class="meta.iconClass" />
                <span v-if="activity.dao">{{ activity.dao.name }}</span>
                <span>·</span>
                <span>{{ timeAgo }}</span>
            </p>
        </div>
    </div>
</template>
