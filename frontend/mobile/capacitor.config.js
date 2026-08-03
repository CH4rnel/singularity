'use strict';

const { allowNavigation, resolveAppUrl } = require('./src/app-url');

const appUrl = resolveAppUrl();
const { hostname, protocol } = new URL(appUrl);

/**
 * The shell renders the live site, so `server.url` is the whole app. `www/` is
 * only the local fallback the WebView shows when that URL cannot be reached
 * (`server.errorPath`); regenerate it with `npm run www`.
 *
 * @type {import('@capacitor/cli').CapacitorConfig}
 */
const config = {
    appId: 'church.cyberia.app',
    appName: 'Cyberia',
    webDir: 'www',
    backgroundColor: '#0b0f10',
    // Lets the backend recognise the native shell (see resources/js/lib/native.ts).
    appendUserAgent: 'CyberiaMobile/1.0.0',
    zoomEnabled: false,
    server: {
        url: appUrl,
        hostname,
        androidScheme: 'https',
        cleartext: protocol === 'http:',
        errorPath: 'error.html',
        allowNavigation: allowNavigation(appUrl),
    },
    android: {
        allowMixedContent: false,
        webContentsDebuggingEnabled: false,
    },
    ios: {
        // The site paints its own safe-area padding, so the WebView must not
        // add another inset on top of it.
        contentInset: 'never',
        limitsNavigationsToAppBoundDomains: false,
    },
    plugins: {
        SplashScreen: {
            launchAutoHide: true,
            launchShowDuration: 500,
            backgroundColor: '#0b0f10',
            androidSplashResourceName: 'splash',
            showSpinner: false,
        },
        StatusBar: {
            style: 'DARK',
            backgroundColor: '#0b0f10',
            overlaysWebView: false,
        },
    },
};

module.exports = config;
