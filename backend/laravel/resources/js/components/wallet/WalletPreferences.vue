<script setup lang="ts">
import { Bell, Power, Volume2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useLocale } from '@/composables/useLocale';
import {
    hasNativeTray,
    nativeShell,
    nativeStartup,
    refreshNativeStartup,
    setNativeStartup,
} from '@/lib/native';
import {
    enableWalletNotifications,
    playWalletSound,
    readWalletPreferences,
    saveWalletPreferences,
    showWalletNotification,
    subscribeWalletPreferences,
    walletNotificationPermission,
} from '@/lib/wallet/notifications';
import { walletMessages } from '@/lib/walletMessages';

defineEmits<{ back: [] }>();

const { t } = useLocale(walletMessages);
const preferences = ref(readWalletPreferences());
const permission = ref(walletNotificationPermission());
const startup = ref(nativeStartup());
const startupBusy = ref(false);
const startupError = ref(false);
const desktop = nativeShell() === 'desktop';
const tray = hasNativeTray();

const notificationsHint = computed(() => {
    if (permission.value === 'unsupported') {
        return t('preferencesNotificationsUnsupported');
    }

    if (permission.value === 'denied') {
        return t('preferencesNotificationsDenied');
    }

    return t('preferencesNotificationsHint');
});

const toggleNotifications = async (enabled: boolean): Promise<void> => {
    if (enabled) {
        await enableWalletNotifications();
    } else {
        saveWalletPreferences({ notifications: false });
    }

    permission.value = walletNotificationPermission();
    preferences.value = readWalletPreferences();
};

const toggleSounds = (enabled: boolean): void => {
    preferences.value = saveWalletPreferences({ sounds: enabled });

    if (enabled) {
        playWalletSound('success', true);
    }
};

const testAlerts = async (): Promise<void> => {
    if (permission.value === 'prompt') {
        await toggleNotifications(true);
    }

    playWalletSound('message', true);
    showWalletNotification(
        {
            title: t('preferencesTestTitle'),
            body: t('preferencesTestBody'),
            tag: 'cyberia-wallet-test',
        },
        true,
    );
};

const toggleStartup = async (enabled: boolean): Promise<void> => {
    startupBusy.value = true;
    startupError.value = false;

    try {
        startup.value = await setNativeStartup(enabled);
        startupError.value =
            startup.value.error !== undefined && startup.value.error !== '';
    } finally {
        startupBusy.value = false;
    }
};

const unsubscribe = subscribeWalletPreferences((next) => {
    preferences.value = next;
});

onMounted(async () => {
    startup.value = await refreshNativeStartup();
});

onBeforeUnmount(unsubscribe);
</script>

<template>
    <div class="cw-stack cw-screen">
        <button type="button" class="cw-back" @click="$emit('back')">
            ← {{ t('navPortfolio') }}
        </button>

        <div style="margin: 24px 0 20px">
            <h2 class="cw-title" style="font-size: 22px">
                {{ t('preferencesTitle') }}
            </h2>
            <p class="cw-prose" style="margin-top: 8px">
                {{ t('preferencesBody') }}
            </p>
        </div>

        <div class="cw-card" style="padding: 0">
            <label class="cw-row" style="padding: 16px; cursor: pointer">
                <Bell :size="18" aria-hidden="true" />
                <span style="flex: 1">
                    <span class="cw-data" style="display: block">{{
                        t('preferencesNotifications')
                    }}</span>
                    <span
                        class="cw-label"
                        style="display: block; margin-top: 4px"
                        >{{ notificationsHint }}</span
                    >
                </span>
                <input
                    type="checkbox"
                    :checked="preferences.notifications"
                    :disabled="
                        permission === 'unsupported' || permission === 'denied'
                    "
                    style="
                        width: 20px;
                        height: 20px;
                        accent-color: var(--cw-accent);
                    "
                    @change="
                        toggleNotifications(
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
            </label>

            <label
                class="cw-row"
                style="
                    padding: 16px;
                    border-top: 1px solid var(--cw-line);
                    cursor: pointer;
                "
            >
                <Volume2 :size="18" aria-hidden="true" />
                <span style="flex: 1">
                    <span class="cw-data" style="display: block">{{
                        t('preferencesSounds')
                    }}</span>
                    <span
                        class="cw-label"
                        style="display: block; margin-top: 4px"
                        >{{ t('preferencesSoundsHint') }}</span
                    >
                </span>
                <input
                    type="checkbox"
                    :checked="preferences.sounds"
                    style="
                        width: 20px;
                        height: 20px;
                        accent-color: var(--cw-accent);
                    "
                    @change="
                        toggleSounds(
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
            </label>

            <label
                v-if="startup.available"
                class="cw-row"
                style="
                    padding: 16px;
                    border-top: 1px solid var(--cw-line);
                    cursor: pointer;
                "
            >
                <Power :size="18" aria-hidden="true" />
                <span style="flex: 1">
                    <span class="cw-data" style="display: block">{{
                        t('preferencesStartup')
                    }}</span>
                    <span
                        class="cw-label"
                        style="display: block; margin-top: 4px"
                        >{{ t('preferencesStartupHint') }}</span
                    >
                </span>
                <input
                    type="checkbox"
                    :checked="startup.enabled"
                    :disabled="startupBusy"
                    style="
                        width: 20px;
                        height: 20px;
                        accent-color: var(--cw-accent);
                    "
                    @change="
                        toggleStartup(
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
            </label>
        </div>

        <p
            v-if="startupError"
            class="cw-note cw-note-bad"
            style="margin-top: 12px"
        >
            {{ t('preferencesStartupError') }}
        </p>

        <p v-if="desktop && tray" class="cw-note" style="margin-top: 12px">
            {{ t('preferencesTrayHint') }}
        </p>

        <button
            type="button"
            class="cw-btn cw-btn-secondary"
            style="height: 48px; margin-top: 16px"
            @click="testAlerts"
        >
            {{ t('preferencesTest') }}
        </button>
    </div>
</template>
