import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import { apiFetch } from '@/lib/http';
import {
    destroy as pushDestroy,
    store as pushStore,
} from '@/routes/push-subscriptions';

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const rawData = window.atob(base64);

    return Uint8Array.from(rawData, (char) => char.charCodeAt(0));
}

const isSubscribed = ref(false);
const isBusy = ref(false);
const error = ref<string | null>(null);

export function useWebPush() {
    const page = usePage();

    const { locale } = useLocale();

    const vapidPublicKey = computed(
        () => (page.props.vapidPublicKey as string | undefined) ?? null,
    );

    const isSupported = computed(
        () =>
            typeof navigator !== 'undefined' &&
            'serviceWorker' in navigator &&
            typeof window !== 'undefined' &&
            'PushManager' in window &&
            !!vapidPublicKey.value,
    );

    onMounted(() => {
        if (!isSupported.value) {
            return;
        }

        void navigator.serviceWorker
            .getRegistration('/sw.js')
            .then((registration) => registration?.pushManager.getSubscription())
            .then((subscription) => {
                isSubscribed.value = !!subscription;
            })
            .catch(() => undefined);
    });

    async function subscribe(): Promise<void> {
        if (!isSupported.value || isBusy.value) {
            return;
        }

        isBusy.value = true;
        error.value = null;

        try {
            const registration =
                await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;

            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(
                    vapidPublicKey.value!,
                ) as BufferSource,
            });

            await apiFetch(pushStore().url, {
                method: 'POST',
                // The server cannot ask a browser what language to write in
                // when it sends the notification days later, so tell it now.
                body: JSON.stringify({
                    ...subscription.toJSON(),
                    locale: locale.value,
                }),
            });

            isSubscribed.value = true;
        } catch (err) {
            error.value =
                err instanceof Error
                    ? err.message
                    : 'Failed to enable push notifications';
        } finally {
            isBusy.value = false;
        }
    }

    async function unsubscribe(): Promise<void> {
        if (!isSupported.value || isBusy.value) {
            return;
        }

        isBusy.value = true;
        error.value = null;

        try {
            const registration =
                await navigator.serviceWorker.getRegistration('/sw.js');
            const subscription =
                await registration?.pushManager.getSubscription();

            if (subscription) {
                await apiFetch(pushDestroy().url, {
                    method: 'DELETE',
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }

            isSubscribed.value = false;
        } catch (err) {
            error.value =
                err instanceof Error
                    ? err.message
                    : 'Failed to disable push notifications';
        } finally {
            isBusy.value = false;
        }
    }

    return {
        isSupported,
        isSubscribed,
        isBusy,
        error,
        subscribe,
        unsubscribe,
    };
}
