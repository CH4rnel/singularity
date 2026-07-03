<script setup lang="ts">
import { Link as InertiaLink } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types/pagination';

const props = defineProps<{
    paginator: Paginated<unknown>;
}>();

const prevUrl = computed(
    () =>
        props.paginator.links.find((link) => link.label.includes('Previous'))
            ?.url ?? null,
);
const nextUrl = computed(
    () =>
        props.paginator.links.find((link) => link.label.includes('Next'))
            ?.url ?? null,
);
</script>

<template>
    <div
        v-if="paginator.last_page > 1"
        class="flex items-center justify-between pt-2"
    >
        <p class="text-xs text-muted-foreground">
            {{ paginator.from ?? 0 }}–{{ paginator.to ?? 0 }} of
            {{ paginator.total }}
        </p>
        <div class="flex gap-2">
            <Button
                v-if="prevUrl"
                variant="outline"
                size="sm"
                as-child
            >
                <InertiaLink :href="prevUrl" preserve-scroll>
                    <ChevronLeft class="h-4 w-4" /> Prev
                </InertiaLink>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                <ChevronLeft class="h-4 w-4" /> Prev
            </Button>

            <Button
                v-if="nextUrl"
                variant="outline"
                size="sm"
                as-child
            >
                <InertiaLink :href="nextUrl" preserve-scroll>
                    Next <ChevronRight class="h-4 w-4" />
                </InertiaLink>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                Next <ChevronRight class="h-4 w-4" />
            </Button>
        </div>
    </div>
</template>
