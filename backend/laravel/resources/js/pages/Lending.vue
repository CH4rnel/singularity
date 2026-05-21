<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Header from '@/components/Header.vue';
</script>

<template>
    <Head title="Cyberia Lending" />

    <div class="min-h-screen bg-background text-foreground">
        <Header />

        <main class="mx-auto flex w-full max-w-5xl flex-1 items-center px-4 py-24">
            <div>
                <p
                    class="mb-3 text-xs tracking-[0.24em] text-muted-foreground uppercase"
                >
                    Cyberia product
                </p>
                <h1 class="text-4xl font-semibold tracking-tight">Lending</h1>
            </div>
        </main>
    </div>
</template>
