// WakeLab Service Worker
// Minimal SW — enables PWA installability.
// Network-first for all requests (homelab needs live data).

const CACHE = 'wakelab-v1';

const PRECACHE = [
    './',
    './assets/style.css',
    './assets/bootstrap/bootstrap.min.css',
    './assets/bootstrap/bootstrap.bundle.min.js',
    './assets/js/api.js',
    './assets/js/ui.js',
    './assets/js/app.js',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

// ── Push notifications ──────────────────────────────────────────────────────
self.addEventListener('push', e => {
    let d;
    if (!e.data) {
        d = { title: 'WakeLab', body: 'Nueva notificación' };
    } else {
        try { d = e.data.json(); } catch { d = { title: 'WakeLab', body: e.data.text() || '…' }; }
    }
    e.waitUntil(
        self.registration.showNotification(d.title || 'WakeLab', {
            body:     d.body  || '',
            icon:     './assets/icons/web-app-manifest-192x192.png',
            badge:    './assets/icons/web-app-manifest-192x192.png',
            tag:      d.tag   || 'wakelab',
            renotify: true,
            vibrate:  [200, 100, 200],
            data:     { url: d.url || './' },
        })
    );
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = e.notification.data?.url || './';
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
            for (const c of list) {
                if (c.url.includes('/WakeLab/') && 'focus' in c) return c.focus();
            }
            return clients.openWindow(url);
        })
    );
});

self.addEventListener('fetch', e => {
    // Skip non-GET and API requests — always live
    if (e.request.method !== 'GET') return;
    if (e.request.url.includes('/php/')) return;

    e.respondWith(
        fetch(e.request)
            .then(resp => {
                const clone = resp.clone();
                caches.open(CACHE).then(c => c.put(e.request, clone));
                return resp;
            })
            .catch(() => caches.match(e.request))
    );
});
