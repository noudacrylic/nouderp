/* Service worker untuk PWA CRM NOUD (aplikasi chat CS di HP).
 * Dilayani via route /cs/sw.js (header Service-Worker-Allowed: /cs/) supaya
 * tidak butuh direktori fisik public/cs (yang akan membayangi route /cs).
 *
 * Strategi sama dengan PWA Karyawan: network-first untuk navigasi, cache hanya
 * cadangan offline. Isi chat SENGAJA tidak di-cache — percakapan yang basi
 * lebih berbahaya daripada layar kosong: CS bisa membalas pertanyaan yang
 * sudah dijawab, atau tidak melihat pesan terakhir pelanggan.
 */
const CACHE = 'noud-cs-v1';
const OFFLINE_URL = '/cs/offline';

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

    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match(req).then((r) => r || caches.match(OFFLINE_URL)))
        );
    }
});

/* ───────────── Web Push ───────────── */

// Chat masuk saat aplikasi ditutup. Berlangganannya lewat endpoint ERP yang
// sama (`/erp/push/*`) — langganan menempel ke pengguna, bukan ke aplikasi.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'NOUD Chat', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'NOUD Chat';
    const options = {
        body: data.body || '',
        icon: '/favicon.png',
        badge: '/favicon.png',
        data: { url: data.url || '/cs' },
        tag: data.tag || undefined,
        renotify: !!data.tag,
        vibrate: [80, 40, 80],
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

// Klik notifikasi → fokus jendela /cs yang sudah terbuka, bukan membuka tab
// baru menumpuk. Kalau tujuannya chat lain, jendela yang ada diarahkan ke sana.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/cs';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const c of wins) {
                if (c.url.includes('/cs') && 'focus' in c) {
                    c.navigate(target).catch(() => {});
                    return c.focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(target);
        })
    );
});
