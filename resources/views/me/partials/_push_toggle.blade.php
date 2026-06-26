@php $vapidPublicKey = config('services.webpush.public_key'); @endphp

@if ($vapidPublicKey)
<div id="push-card" class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 hidden">
    <div class="flex items-center gap-3.5">
        <span class="w-11 h-11 shrink-0 rounded-xl flex items-center justify-center bg-amber-100 text-amber-700">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
        </span>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-bold text-slate-800">Notifikasi</p>
            <p id="push-desc" class="text-[11px] text-slate-400 mt-0.5">Dapatkan pemberitahuan izin &amp; info penting.</p>
        </div>
        <button id="push-toggle" type="button"
                class="shrink-0 text-xs font-bold px-3.5 py-2 rounded-lg bg-emerald-600 text-white active:bg-emerald-700 disabled:opacity-50">
            Aktifkan
        </button>
    </div>
    <p id="push-ios-hint" class="hidden text-[11px] text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-2.5 py-2 mt-3">
        📱 Di iPhone: tambahkan dulu aplikasi ini ke <b>Layar Utama</b> (tombol Bagikan → "Tambah ke Layar Utama"), lalu buka dari ikonnya untuk mengaktifkan notifikasi.
    </p>
</div>

@push('scripts')
<script>
(function () {
    const VAPID_PUBLIC_KEY = @json($vapidPublicKey);
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const URLS = {
        subscribe:   @json(route('me.push.subscribe')),
        unsubscribe: @json(route('me.push.unsubscribe')),
        test:        @json(route('me.push.test')),
    };

    const card   = document.getElementById('push-card');
    const btn     = document.getElementById('push-toggle');
    const desc    = document.getElementById('push-desc');
    const iosHint = document.getElementById('push-ios-hint');
    if (!card || !btn) return;

    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

    card.classList.remove('hidden');

    // iOS Safari (belum di-install) tak bisa Web Push.
    if (!supported || (isIOS && !isStandalone)) {
        btn.classList.add('hidden');
        if (isIOS && !isStandalone) {
            iosHint.classList.remove('hidden');
        } else {
            desc.textContent = 'Notifikasi tidak didukung di browser ini.';
        }
        return;
    }

    function b64ToUint8(base64) {
        const padding = '='.repeat((4 - base64.length % 4) % 4);
        const b64 = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(b64);
        return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
    }

    function api(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: body ? JSON.stringify(body) : null,
        });
    }

    let busy = false;
    function setState(active) {
        btn.textContent = active ? 'Matikan' : 'Aktifkan';
        btn.className = 'shrink-0 text-xs font-bold px-3.5 py-2 rounded-lg disabled:opacity-50 '
            + (active ? 'bg-slate-100 text-slate-600 active:bg-slate-200'
                      : 'bg-emerald-600 text-white active:bg-emerald-700');
        desc.textContent = active ? 'Notifikasi aktif di perangkat ini.'
                                  : 'Dapatkan pemberitahuan izin & info penting.';
    }

    async function currentSub() {
        const reg = await navigator.serviceWorker.ready;
        return reg.pushManager.getSubscription();
    }

    async function enable() {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
            desc.textContent = 'Izin notifikasi ditolak. Aktifkan lewat pengaturan browser.';
            return;
        }
        const reg = await navigator.serviceWorker.ready;
        const sub = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: b64ToUint8(VAPID_PUBLIC_KEY),
        });
        const json = sub.toJSON();
        await api(URLS.subscribe, {
            endpoint: sub.endpoint,
            keys: json.keys,
            contentEncoding: (PushManager.supportedContentEncodings || ['aesgcm'])[0],
        });
        setState(true);
        api(URLS.test); // kirim notif percobaan
    }

    async function disable() {
        const sub = await currentSub();
        if (sub) {
            await api(URLS.unsubscribe, { endpoint: sub.endpoint });
            await sub.unsubscribe();
        }
        setState(false);
    }

    btn.addEventListener('click', async () => {
        if (busy) return;
        busy = true; btn.disabled = true;
        try {
            const sub = await currentSub();
            await (sub ? disable() : enable());
        } catch (e) {
            desc.textContent = 'Gagal mengubah notifikasi. Coba lagi.';
        } finally {
            busy = false; btn.disabled = false;
        }
    });

    // Status awal
    currentSub().then((sub) => setState(!!sub)).catch(() => {});
})();
</script>
@endpush
@endif
