<script setup lang="ts">
import { Link as InertiaLink, router, usePage } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { Reply, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import ReactionBar from '@/components/dao/ReactionBar.vue';
import { Button } from '@/components/ui/button';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import { destroy as commentDestroy } from '@/routes/comments';
import { show as userShow } from '@/routes/users';
import type { ProposalComment, User } from '@/types';

const props = withDefaults(
    defineProps<{
        comment: ProposalComment;
        isReply?: boolean;
    }>(),
    { isReply: false },
);

const emit = defineEmits<{
    reply: [comment: ProposalComment];
}>();

const page = usePage();
const authUser = computed(() => page.props.auth?.user as User | undefined);
const isMine = computed(
    () => !!authUser.value && props.comment.user_id === authUser.value.id,
);

const timeAgo = useTimeAgo(computed(() => props.comment.created_at));

function deleteComment() {
    if (confirm('Delete this comment?')) {
        router.delete(commentDestroy(props.comment.id).url, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <div class="flex gap-3" :class="isReply ? 'mt-3' : ''">
        <InertiaLink :href="userShow(comment.user_id).url" class="shrink-0">
            <WalletAvatar
                :seed="comment.user?.wallet_address"
                :name="comment.user?.name"
                :size="isReply ? 'sm' : 'md'"
            />
        </InertiaLink>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <InertiaLink
                    :href="userShow(comment.user_id).url"
                    class="font-semibold hover:underline"
                >
                    {{ comment.user?.name || 'Unknown' }}
                </InertiaLink>
                <span class="text-xs text-muted-foreground">{{ timeAgo }}</span>
            </div>

            <p class="mt-1 text-sm leading-relaxed whitespace-pre-wrap">
                {{ comment.body }}
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-3">
                <ReactionBar
                    reactable-type="comment"
                    :reactable-id="comment.id"
                    :reactions="comment.reactions"
                    compact
                />
                <Button
                    v-if="!isReply && authUser"
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs text-muted-foreground"
                    @click="emit('reply', comment)"
                >
                    <Reply class="mr-1 h-3 w-3" /> Reply
                </Button>
                <Button
                    v-if="isMine"
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs text-muted-foreground hover:text-destructive"
                    @click="deleteComment"
                >
                    <Trash2 class="mr-1 h-3 w-3" /> Delete
                </Button>
            </div>

            <!-- One level of replies -->
            <div
                v-if="comment.replies && comment.replies.length > 0"
                class="mt-1 border-l-2 border-border/60 pl-4"
            >
                <CommentItem
                    v-for="reply in comment.replies"
                    :key="reply.id"
                    :comment="reply"
                    is-reply
                />
            </div>

            <slot name="reply-form" />
        </div>
    </div>
</template>
