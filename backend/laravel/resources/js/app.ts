// The `buffer` userland polyfill uses CommonJS `require`, which breaks under
// Vite's SSR ESM runner. Node already provides a native Buffer, so only load
// the polyfill in the browser.
if (!import.meta.env.SSR) {
    const { Buffer } = await import('buffer');
    globalThis.Buffer = Buffer;
}

import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Analytics':
            case name === 'Bridge':
            case name === 'Market':
            case name === 'Lending':
            case name === 'Farm':
            case name === 'Launchpad':
            case name === 'CyberSolSwap':
            case name === 'Slots':
            case name.startsWith('dao/'):
            case name.startsWith('proposals/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
