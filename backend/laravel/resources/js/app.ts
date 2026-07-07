// Install the browser `Buffer` global before anything else evaluates. Must be
// the first import so the global exists before any static- or lazily-imported
// module (e.g. the Solana-using bridge chunk) references it. See polyfills.ts.
import './polyfills';

import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import Web3Layout from '@/layouts/Web3Layout.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Analytics':
            case name === 'Token':
            case name === 'Tokens':
            case name === 'Bridge':
            case name === 'Market':
            case name === 'Lending':
            case name === 'Liquidate':
            case name === 'Liquidity':
            case name === 'Farm':
            case name === 'Launchpad':
            case name === 'CyberSolSwap':
            case name === 'Swap':
            case name === 'Slots':
            case name === 'Predictions':
            case name === 'PixelBattle':
            case name.startsWith('dao/'):
            case name.startsWith('proposals/'):
            case name.startsWith('users/'):
                return Web3Layout;
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
        color: '#00e5d1',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
