/**
 * Cyberia service worker — Web Push delivery only (no offline caching).
 * Payloads are JSON: { title, body, url } (see DaoActivityNotification).
 */

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch {
        payload = { title: 'Cyberia', body: event.data.text() };
    }

    const title = payload.title || 'Cyberia';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/apple-touch-icon.png',
        badge: '/favicon.ico',
        data: { url: (payload.data && payload.data.url) || payload.url || '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = (event.notification.data && event.notification.data.url) || '/';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                for (const client of clients) {
                    const clientPath = new URL(client.url).pathname;
                    const targetPath = new URL(url, self.location.origin)
                        .pathname;

                    if (clientPath === targetPath && 'focus' in client) {
                        return client.focus();
                    }
                }

                return self.clients.openWindow(url);
            }),
    );
});
