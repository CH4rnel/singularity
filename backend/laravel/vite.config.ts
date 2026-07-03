import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        inertia(),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        wayfinder({
            formVariants: true,
        }),
    ],
    // The Solana web3 stack needs the browser `buffer` polyfill, but only in
    // the client bundle: the polyfill's CJS entry breaks Vite's ESM SSR
    // runner, while SSR runs in Node where the built-in Buffer already works.
    environments: {
        client: {
            resolve: {
                alias: {
                    buffer: 'buffer/',
                },
            },
        },
    },
});
