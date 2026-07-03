<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DaoDirectory from '@/components/dao/DaoDirectory.vue';
import FeedItem from '@/components/dao/FeedItem.vue';
import SimplePagination from '@/components/web3/SimplePagination.vue';
import type { Activity, Dao } from '@/types';
import type { Paginated } from '@/types/pagination';

type Props = {
    activities: Paginated<Activity>;
    daos: Dao[];
};

const props = defineProps<Props>();
</script>

<template>
    <Head title="DAO" />

    <div class="mx-auto max-w-6xl px-4 py-8">
        <header class="mb-6">
            <h1 class="text-2xl font-extrabold tracking-tight">
                DAO<span class="text-brand-cyan">_</span>
            </h1>
            <p class="text-sm text-muted-foreground">
                Live activity across all Cyberia DAOs — proposals, votes and
                discussions.
            </p>
        </header>

        <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
            <section class="space-y-3">
                <FeedItem
                    v-for="activity in props.activities.data"
                    :key="activity.id"
                    :activity="activity"
                />

                <p
                    v-if="props.activities.data.length === 0"
                    class="rounded-lg border border-dashed py-12 text-center text-sm text-muted-foreground"
                >
                    Nothing here yet — create the first proposal.
                </p>

                <SimplePagination :paginator="props.activities" />
            </section>

            <aside>
                <DaoDirectory :daos="props.daos" />
            </aside>
        </div>
    </div>
</template>
