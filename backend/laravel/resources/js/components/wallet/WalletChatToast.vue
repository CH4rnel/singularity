<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { onBeforeUnmount, watch } from 'vue';
import WalletChatAvatar from '@/components/wallet/WalletChatAvatar.vue';
import { useLocale } from '@/composables/useLocale';
import { walletMessages } from '@/lib/walletMessages';

/**
 * "Somebody wrote to you", wherever in the wallet you are.
 *
 * The badge on the tab bar says that there is mail; this says who and what,
 * at the moment it arrives, and one tap opens the thread. It goes away by
 * itself — a message is not an alert, and nothing here needs dismissing to
 * carry on.
 */
export type ChatToast = {
    peer: string;
    name: string;
    avatar: string | null;
    text: string;
    count: number;
    /** Changes with every arrival, so a second message restarts the timer. */
    at: number;
};

const props = defineProps<{ toast: ChatToast | null }>();

const emit = defineEmits<{
    open: [peer: string];
    dismiss: [];
}>();

const { t } = useLocale(walletMessages);

/** Long enough to read two lines, short enough to be gone before it nags. */
const VISIBLE_MS = 6_500;

let timer: ReturnType<typeof setTimeout> | null = null;

const clear = (): void => {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
};

watch(
    () => props.toast?.at,
    (at) => {
        clear();

        if (at !== undefined) {
            timer = setTimeout(() => emit('dismiss'), VISIBLE_MS);
        }
    },
);

onBeforeUnmount(clear);
</script>

<template>
    <Transition name="cw-dm-toast">
        <div
            v-if="toast"
            :key="toast.peer"
            class="cw-dm-toast"
            role="status"
            aria-live="polite"
        >
            <button
                type="button"
                class="cw-dm-toast-open"
                @click="emit('open', toast.peer)"
            >
                <WalletChatAvatar
                    :address="toast.peer"
                    :name="toast.name"
                    :src="toast.avatar"
                    :size="40"
                />
                <span class="cw-dm-toast-body">
                    <span class="cw-dm-toast-name">
                        {{ toast.name }}
                        <span v-if="toast.count > 1" class="cw-dm-toast-more">
                            {{ t('chatToastMore', { count: toast.count }) }}
                        </span>
                    </span>
                    <span class="cw-dm-toast-text">{{ toast.text }}</span>
                </span>
            </button>
            <button
                type="button"
                class="cw-dm-toast-close"
                :aria-label="t('chatToastClose')"
                @click="emit('dismiss')"
            >
                <X :size="16" aria-hidden="true" />
            </button>
        </div>
    </Transition>
</template>
