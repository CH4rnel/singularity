<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Loader2, MessageSquare } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import CommentItem from '@/components/dao/CommentItem.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import SimplePagination from '@/components/web3/SimplePagination.vue';
import { store as commentStore } from '@/routes/proposals/comments';
import type { ProposalComment, User } from '@/types';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    proposalId: number;
    comments: Paginated<ProposalComment>;
}>();

const page = usePage();
const isAuthenticated = computed(() => !!(page.props.auth?.user as User | undefined));

const replyingTo = ref<ProposalComment | null>(null);

const form = useForm({
    body: '',
    parent_id: null as number | null,
});

function startReply(comment: ProposalComment) {
    replyingTo.value = comment;
    form.parent_id = comment.id;
    form.body = '';
}

function cancelReply() {
    replyingTo.value = null;
    form.parent_id = null;
    form.body = '';
}

function submit() {
    form.post(commentStore(props.proposalId).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            replyingTo.value = null;
        },
    });
}
</script>

<template>
    <section class="space-y-5 rounded-lg border border-border/70 bg-card p-5">
        <h3 class="flex items-center gap-2 text-lg font-medium">
            <MessageSquare class="h-5 w-5 text-brand-magenta" />
            Discussion
            <span class="font-mono text-sm text-muted-foreground">
                {{ comments.total }}
            </span>
        </h3>

        <!-- Top-level comment form -->
        <form
            v-if="isAuthenticated && !replyingTo"
            class="space-y-2"
            @submit.prevent="submit"
        >
            <textarea
                v-model="form.body"
                class="block min-h-[80px] w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm"
                placeholder="Write a comment..."
                required
            />
            <InputError :message="form.errors.body" />
            <Button type="submit" size="sm" :disabled="form.processing">
                <Loader2
                    v-if="form.processing"
                    class="mr-1 h-4 w-4 animate-spin"
                />
                Post Comment
            </Button>
        </form>

        <!-- Comments -->
        <div class="space-y-5">
            <CommentItem
                v-for="comment in comments.data"
                :key="comment.id"
                :comment="comment"
                @reply="startReply"
            >
                <template v-if="replyingTo?.id === comment.id" #reply-form>
                    <form class="mt-3 space-y-2" @submit.prevent="submit">
                        <textarea
                            v-model="form.body"
                            class="block min-h-[60px] w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm"
                            :placeholder="`Reply to ${comment.user?.name || 'comment'}...`"
                            required
                        />
                        <InputError :message="form.errors.body" />
                        <InputError :message="form.errors.parent_id" />
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="form.processing"
                            >
                                <Loader2
                                    v-if="form.processing"
                                    class="mr-1 h-4 w-4 animate-spin"
                                />
                                Reply
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="cancelReply"
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                </template>
            </CommentItem>
        </div>

        <p
            v-if="comments.data.length === 0"
            class="py-4 text-center text-sm text-muted-foreground"
        >
            {{
                isAuthenticated
                    ? 'No comments yet. Be the first to comment.'
                    : 'No comments yet.'
            }}
        </p>

        <SimplePagination :paginator="comments" />
    </section>
</template>
