/*
 * Alima service worker — Step B-7.1 (offline shell) and B-7.4 (push reminders).
 *
 * Deliberately hand-written and dependency-free. Two jobs:
 *   1. An offline shell: hashed build assets are cached first (they're immutable), and a failed
 *      navigation falls back to a branded /offline.html. No offline *writes* — a POST never touches
 *      the cache, so the share target and every form still hit the server.
 *   2. Web push: render "Post goes out in 30 minutes" / failure / "nothing scheduled" notifications
 *      and focus the app when one is tapped.
 *
 * Bump CACHE_VERSION to retire old caches on the next activation.
 */
const CACHE_VERSION = 'alima-v1';
const PRECACHE = `${CACHE_VERSION}-precache`;
const RUNTIME = `${CACHE_VERSION}-runtime`;

// The minimal offline shell. Icons are tiny and let the fallback page render while offline.
const PRECACHE_URLS = ['/offline.html', '/icons/icon-192.png'];

// Same-origin, content-hashed or immutable assets we can safely cache first.
const CACHEABLE_PREFIXES = ['/build/', '/icons/', '/brand/'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(PRECACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => !key.startsWith(CACHE_VERSION))
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

function isCacheable(url) {
    return CACHEABLE_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Never interfere with writes (the share target POST, forms, API calls) or cross-origin requests.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Navigations: always try the network first (pages are per-user and must stay fresh); only when
    // the network is unreachable do we show the offline shell. We never cache the HTML itself.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html')),
        );
        return;
    }

    // Immutable static assets: serve from cache, revalidate in the background (stale-while-revalidate).
    if (isCacheable(url)) {
        event.respondWith(
            caches.open(RUNTIME).then((cache) =>
                cache.match(request).then((cached) => {
                    const network = fetch(request)
                        .then((response) => {
                            if (response && response.ok) {
                                cache.put(request, response.clone());
                            }
                            return response;
                        })
                        .catch(() => cached);

                    return cached || network;
                }),
            ),
        );
    }
});

/* ---- Step B-7.4: web push ---- */

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { title: 'Alima', body: event.data ? event.data.text() : '' };
    }

    const title = payload.title || 'Alima';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: payload.tag || undefined,
        data: { url: payload.url || '/today' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const target =
        (event.notification.data && event.notification.data.url) || '/today';

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if ('focus' in client) {
                        client.navigate(target);
                        return client.focus();
                    }
                }

                return self.clients.openWindow(target);
            }),
    );
});
