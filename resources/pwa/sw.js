/* Service worker minimal untuk PWA Karyawan NOUD.
 * Dilayani via route /me/sw.js (header Service-Worker-Allowed: /me/) supaya
 * tidak butuh direktori fisik public/me (yang akan membayangi route /me).
 * Strategi: network-first untuk navigasi (selalu data terbaru saat online),
 * cache hanya cadangan offline — TIDAK meng-cache data absensi agar tak basi.
 */
const CACHE = 'noud-karyawan-v1';
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
