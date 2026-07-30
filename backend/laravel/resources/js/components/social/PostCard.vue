<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { computed } from 'vue';
import WalletAvatar from '@/components/web3/WalletAvatar.vue';
import type { Post } from '@/types/social';

const props = defineProps<{
    post: Post;
}>();

const createdAgo = useTimeAgo(computed(() => props.post.created_at));
</script>

<template>
    <article class="flex gap-3 rounded-lg border border-border/70 bg-card p-4">
        <Link :href="post.user.profile_url" class="shrink-0">
            <WalletAvatar
                :seed="post.user.wallet_address"
                :name="post.user.name"
                :src="post.user.avatar"
            />
        </Link>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <Link
                    :href="post.user.profile_url"
                    class="font-semibold hover:underline"
                >
                    {{ post.user.name }}
                </Link>
                <time
                    :datetime="post.created_at"
                    class="text-xs text-muted-foreground"
                >
                    {{ createdAgo }}
                </time>
            </div>
            <p class="mt-2 text-sm leading-6 break-words whitespace-pre-wrap">
                {{ post.body }}
            </p>
        </div>
    </article>
</template>
