<script setup lang="ts">
import { Download, Share, X } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { usePwaInstall } from '@/composables/usePwaInstall';

const { canInstall, dismiss, install, showIosInstructions } = usePwaInstall();

const isVisible = computed(() => canInstall.value || showIosInstructions.value);
</script>

<template>
    <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-4 opacity-0"
    >
        <aside
            v-if="isVisible"
            role="region"
            aria-live="polite"
            aria-labelledby="pwa-install-title"
            class="fixed right-4 bottom-4 left-4 z-[100] mx-auto max-w-md overflow-hidden rounded-xl border border-brand-cyan/35 bg-card/95 p-4 text-card-foreground shadow-2xl shadow-black/35 backdrop-blur-xl sm:right-6 sm:bottom-6 sm:left-auto"
        >
            <div class="flex items-start gap-3">
                <img
                    src="/pwa/icon-192.png"
                    alt=""
                    class="size-12 shrink-0 rounded-xl"
                />
                <div class="min-w-0 flex-1">
                    <h2
                        id="pwa-install-title"
                        class="font-extrabold tracking-tight"
                    >
                        Install Cyberia
                    </h2>
                    <p class="mt-1 text-sm leading-5 text-muted-foreground">
                        <template v-if="canInstall">
                            Open Cyberia from your home screen in its own app
                            window.
                        </template>
                        <template v-else>
                            On iPhone or iPad, tap Share
                            <Share class="mx-0.5 inline size-3.5" />
                            and then “Add to Home Screen”.
                        </template>
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <Button
                            v-if="canInstall"
                            size="sm"
                            type="button"
                            @click="install"
                        >
                            <Download />
                            Install
                        </Button>
                        <Button
                            v-else
                            size="sm"
                            type="button"
                            variant="outline"
                            @click="dismiss"
                        >
                            Got it
                        </Button>
                        <Button
                            v-if="canInstall"
                            size="sm"
                            type="button"
                            variant="ghost"
                            @click="dismiss"
                        >
                            Not now
                        </Button>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Dismiss install prompt"
                    class="-mt-1 -mr-1"
                    @click="dismiss"
                >
                    <X />
                </Button>
            </div>
        </aside>
    </Transition>
</template>
