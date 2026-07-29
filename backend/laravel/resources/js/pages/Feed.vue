<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import PostCard from '@/components/social/PostCard.vue';
import PostComposer from '@/components/social/PostComposer.vue';
import { Button } from '@/components/ui/button';
import PageHero from '@/components/web3/PageHero.vue';
import SimplePagination from '@/components/web3/SimplePagination.vue';
import { login } from '@/routes';
import type { Auth } from '@/types/auth';
import type { Paginated } from '@/types/pagination';
import type { Post } from '@/types/social';

defineProps<{
    posts: Paginated<Post>;
}>();

const page = usePage<{ auth: Auth | { user: null } }>();
</script>

<template>
    <Head title="Feed" />

    <div class="mx-auto max-w-3xl space-y-6 px-4 py-8">
        <PageHero
            eyebrow="Community"
            title="Feed"
            description="Posts from people across Cyberia, newest first."
        />

        <PostComposer v-if="page.props.auth.user" />
        <div
            v-else
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed p-4"
        >
            <p class="text-sm text-muted-foreground">
                Sign in to write a post.
            </p>
            <Button variant="outline" size="sm" as-child>
                <Link :href="login().url">Sign in</Link>
            </Button>
        </div>

        <section class="space-y-3" aria-label="Community posts">
            <PostCard v-for="post in posts.data" :key="post.id" :post="post" />
            <p
                v-if="posts.data.length === 0"
                class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
            >
                The feed is quiet. Write the first post.
            </p>
            <SimplePagination :paginator="posts" />
        </section>
    </div>
</template>
