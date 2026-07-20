/* Service worker minimal untuk notifikasi Web Push di aplikasi ERP desktop (tim packing).
 * Dilayani via route /erp/sw.js (scope /erp/). SENGAJA tanpa handler fetch/cache: ERP
 * bukan PWA offline — SW ini hanya untuk menerima push & membuka halaman saat diklik,
 * agar tidak mengganggu autentikasi/redirect aplikasi.
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

/* ───────────── Web Push ───────────── */

// Terima notifikasi dari server → tampilkan ke user (walau tab ERP ditutup).
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { title: 'NOUD ERP', body: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'NOUD ERP';
    const options = {
        body: data.body || '',
        icon: '/favicon.png',
        badge: '/favicon.png',
        data: { url: data.url || '/erp/pos/fulfillment/perlu-diproses' },
        tag: data.tag || undefined,
        renotify: !!data.tag,
        requireInteraction: true, // pesanan instant penting → jangan hilang otomatis
        vibrate: [80, 40, 80],
    };

    // Tampilkan notifikasi + minta tab ERP yang terbuka memutar suara. Service worker
    // tak bisa memutar audio sendiri; kalau tak ada tab terbuka, suara default OS yang
    // berbunyi bersama notifikasi. Tab yang membuka halaman ERP-lah yang berbunyi khas.
    event.waitUntil(Promise.all([
        self.registration.showNotification(title, options),
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const c of wins) {
                c.postMessage({ type: 'noud-push', title: title, body: options.body, tag: options.tag });
            }
        }),
    ]));
});

// Klik notifikasi → fokus tab ERP yang sudah ada atau buka halaman tujuan.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = (event.notification.data && event.notification.data.url) || '/erp/pos/fulfillment/perlu-diproses';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
            for (const c of wins) {
                if (c.url.includes('/erp') && 'focus' in c) {
                    c.navigate(target).catch(() => {});
                    return c.focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(target);
        })
    );
});
