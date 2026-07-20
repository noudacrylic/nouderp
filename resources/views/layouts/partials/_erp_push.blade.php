{{-- Web Push notifikasi pesanan untuk tim packing (ERP desktop).
     Registrasi service worker + logika toggle tombol #erpPushToggle di user-menu popup.
     No-op bila VAPID belum diatur (tombol tak dirender di erp.blade.php). --}}
@php $vapidPublicKey = config('services.webpush.public_key'); @endphp
@if ($vapidPublicKey)
@push('scripts')
<script>
(function () {
    const VAPID_PUBLIC_KEY = @json($vapidPublicKey);
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const URLS = {
        subscribe:   @json(route('pos.api.push-subscribe')),
        unsubscribe: @json(route('pos.api.push-unsubscribe')),
        test:        @json(route('pos.api.push-test')),
    };

    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (!supported) return;

    // Registrasi service worker (scope /erp/) — dilakukan di setiap halaman ERP agar
    // langganan tetap aktif walau user pindah halaman.
    navigator.serviceWorker.register('/erp/sw.js', { scope: '/erp/' }).catch(() => {});

    // ───────────── Suara notifikasi ─────────────
    // Web Audio (bukan file audio) agar tak butuh aset & bisa berbunyi khas/berulang.
    // Kebijakan autoplay browser menuntut AudioContext dibuka lewat interaksi user, maka
    // kita "buka kunci" pada gerakan/klik pertama di halaman (mengaktifkan notif = klik).
    let audioCtx = null;
    function ensureAudio() {
        try {
            if (!audioCtx) {
                const AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return null;
                audioCtx = new AC();
            }
            if (audioCtx.state === 'suspended') audioCtx.resume();
        } catch (e) { return null; }
        return audioCtx;
    }
    ['pointerdown', 'keydown'].forEach((ev) =>
        window.addEventListener(ev, ensureAudio, { passive: true }));

    function playChime() {
        const ctx = ensureAudio();
        if (!ctx) return;
        const now = ctx.currentTime;

        // Compressor/limiter → memaksimalkan kekerasan (loudness) tanpa distorsi kasar
        // walau gain dinaikkan tinggi. Master gain sedikit di bawah 1 sebagai pengaman.
        const comp = ctx.createDynamicsCompressor();
        comp.threshold.value = -18; comp.ratio.value = 12;
        comp.attack.value = 0.003; comp.release.value = 0.2;
        const master = ctx.createGain();
        master.gain.value = 0.95;
        comp.connect(master).connect(ctx.destination);

        // Tiga nada naik (A5 → A5 → D6) supaya mencolok — pesanan instant perlu segera diproses.
        // Gelombang square lebih kaya harmonik → terdengar jauh lebih nyaring daripada sinus.
        [[880, 0], [880, 0.2], [1175, 0.4]].forEach(([freq, t]) => {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'square';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, now + t);
            gain.gain.exponentialRampToValueAtTime(0.9, now + t + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + t + 0.2);
            osc.connect(gain).connect(comp);
            osc.start(now + t);
            osc.stop(now + t + 0.21);
        });
    }

    // Service worker mengirim pesan saat push tiba → mainkan suara di halaman.
    navigator.serviceWorker.addEventListener('message', (e) => {
        if (e.data && e.data.type === 'noud-push') playChime();
    });

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

    async function currentSub() {
        const reg = await navigator.serviceWorker.ready;
        return reg.pushManager.getSubscription();
    }

    async function enable() {
        ensureAudio(); // buka kunci audio pada klik aktivasi (gerakan user)
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') {
            alert('Izin notifikasi ditolak. Aktifkan lewat pengaturan browser (ikon gembok di address bar).');
            return false;
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
        api(URLS.test); // notif percobaan
        return true;
    }

    async function disable() {
        const sub = await currentSub();
        if (sub) {
            await api(URLS.unsubscribe, { endpoint: sub.endpoint });
            await sub.unsubscribe();
        }
        return true;
    }

    document.addEventListener('DOMContentLoaded', function () {
        const btn   = document.getElementById('erpPushToggle');
        const label = document.getElementById('erpPushLabel');
        if (!btn) return;
        btn.style.display = '';

        let busy = false;
        function render(active) {
            if (label) label.textContent = active ? 'Matikan Notifikasi Pesanan' : 'Aktifkan Notifikasi Pesanan';
        }

        currentSub().then((sub) => render(!!sub)).catch(() => {});

        btn.addEventListener('click', async function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (busy) return;
            busy = true;
            try {
                const sub = await currentSub();
                const ok = await (sub ? disable() : enable());
                if (ok) render(!sub);
            } catch (err) {
                alert('Gagal mengubah notifikasi. Coba lagi.');
            } finally {
                busy = false;
            }
        });
    });
})();
</script>
@endpush
@endif
