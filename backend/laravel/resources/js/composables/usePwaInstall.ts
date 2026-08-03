import { computed, ref, shallowRef } from 'vue';
import { isNativeShell } from '@/lib/native';

type InstallChoiceOutcome = 'accepted' | 'dismissed';

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{
        outcome: InstallChoiceOutcome;
        platform: string;
    }>;
}

type NavigatorWithStandalone = Navigator & {
    standalone?: boolean;
};

const DISMISS_STORAGE_KEY = 'cyberia.pwa.install-dismissed-at';
const DISMISS_DURATION_MS = 30 * 24 * 60 * 60 * 1000;

const deferredPrompt = shallowRef<BeforeInstallPromptEvent | null>(null);
const isDismissed = ref(false);
const isIos = ref(false);
const isStandalone = ref(false);

let initialized = false;

function wasDismissedRecently(): boolean {
    try {
        const dismissedAt = Number(
            window.localStorage.getItem(DISMISS_STORAGE_KEY),
        );

        return (
            Number.isFinite(dismissedAt) &&
            dismissedAt > 0 &&
            Date.now() - dismissedAt < DISMISS_DURATION_MS
        );
    } catch {
        return false;
    }
}

function rememberDismissal(): void {
    try {
        window.localStorage.setItem(DISMISS_STORAGE_KEY, Date.now().toString());
    } catch {
        // The prompt still closes when storage is unavailable.
    }
}

function detectStandaloneMode(): boolean {
    return (
        // Inside the desktop or mobile app the site is already installed, so
        // every install affordance has to stay hidden.
        isNativeShell() ||
        window.matchMedia('(display-mode: standalone)').matches ||
        Boolean((window.navigator as NavigatorWithStandalone).standalone)
    );
}

function detectIos(): boolean {
    const navigator = window.navigator;

    return (
        /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    );
}

function registerServiceWorker(): void {
    if (!('serviceWorker' in window.navigator)) {
        return;
    }

    const register = (): void => {
        void window.navigator.serviceWorker
            .register('/sw.js')
            .catch(() => undefined);
    };

    if (document.readyState === 'complete') {
        register();

        return;
    }

    window.addEventListener('load', register, { once: true });
}

export function initializePwa(): void {
    if (initialized || typeof window === 'undefined') {
        return;
    }

    initialized = true;
    isDismissed.value = wasDismissedRecently();
    isStandalone.value = detectStandaloneMode();
    isIos.value = detectIos();

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();

        if (!isDismissed.value && !isStandalone.value) {
            deferredPrompt.value = event as BeforeInstallPromptEvent;
        }
    });

    window.addEventListener('appinstalled', () => {
        deferredPrompt.value = null;
        isStandalone.value = true;
    });

    registerServiceWorker();
}

export function usePwaInstall() {
    const canInstall = computed(
        () =>
            deferredPrompt.value !== null &&
            !isDismissed.value &&
            !isStandalone.value,
    );

    const showIosInstructions = computed(
        () => isIos.value && !isDismissed.value && !isStandalone.value,
    );

    const dismiss = (): void => {
        deferredPrompt.value = null;
        isDismissed.value = true;
        rememberDismissal();
    };

    const install = async (): Promise<void> => {
        const prompt = deferredPrompt.value;

        if (!prompt) {
            return;
        }

        deferredPrompt.value = null;
        await prompt.prompt();
        const choice = await prompt.userChoice;

        if (choice.outcome === 'dismissed') {
            isDismissed.value = true;
            rememberDismissal();
        }
    };

    return {
        canInstall,
        dismiss,
        install,
        showIosInstructions,
    };
}
