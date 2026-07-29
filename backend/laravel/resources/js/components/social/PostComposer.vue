<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Send } from 'lucide-vue-next';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { store as postStore } from '@/routes/posts';

const form = useForm({
    body: '',
});

const remaining = computed(() => 2000 - form.body.length);

function submit() {
    form.submit(postStore(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <form
        class="space-y-3 rounded-lg border border-border/70 bg-card p-4"
        @submit.prevent="submit"
    >
        <label for="social-post-body" class="text-sm font-semibold">
            Write a post
        </label>
        <textarea
            id="social-post-body"
            v-model="form.body"
            name="body"
            maxlength="2000"
            required
            class="block min-h-28 w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            placeholder="What is happening in Cyberia?"
        ></textarea>
        <InputError :message="form.errors.body" />
        <div class="flex items-center justify-between gap-3">
            <span
                class="font-mono text-xs"
                :class="
                    remaining < 100
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{ remaining }}
            </span>
            <Button
                type="submit"
                size="sm"
                :disabled="form.processing || form.body.trim().length === 0"
            >
                <Send class="h-3.5 w-3.5" />
                {{ form.processing ? 'Posting…' : 'Post' }}
            </Button>
        </div>
    </form>
</template>
