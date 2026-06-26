/* Service worker minimal untuk PWA Karyawan NOUD.
 * Dilayani via route /me/sw.js (header Service-Worker-Allowed: /me/) supaya
 * tidak butuh direktori fisik public/me (yang akan membayangi route /me).
 * Strategi: network-first untuk navigasi (selalu data terbaru saat online),
 * cache hanya cadangan offline — TIDAK meng-cache data absensi agar tak basi.
 */
const CACHE = 'noud-karyawan-v2';
const OFFLINE_URL = '/me/offline';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    // Hanya tangani navigasi (HTML). Aset (CDN) dibiarkan default browser.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(req).then((r) => r || caches.match(OFFLINE_URL)))
        );
    }
});

/* ───────────── Web Push ───────────── */

// Terima notifikasi dari server → tampilkan ke karyawan (walau app ditutup).
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'NOUD Karyawan', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'NOUD Karyawan';
    const options = {
        body: data.body || '',
        icon: '/favicon.png',
        badge: '/favicon.png',
        data: { url: data.url || '/me' },
        tag: data.tag || undefined,
        renotify: !!data.tag,
        vibrate: [80, 40, 80],
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Klik notifikasi → fokus tab yang sudah ada atau buka halaman tujuan.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/me';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const c of wins) {
                if (c.url.includes('/me') && 'focus' in c) {
                    c.navigate(target).catch(() => {});
                    return c.focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(target);
        })
    );
});
