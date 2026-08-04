{{-- Reusable Midtrans modals + JS. Include once per page yang punya tombol Midtrans.
     Mengandalkan window._midtransOpen(invoiceId, mode) di-fire dari tombol. --}}

<div id="md_link_modal" class="fixed inset-0 z-[10000] hidden bg-black/50 items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b flex justify-between items-center bg-emerald-50">
            <h3 class="font-bold text-emerald-800">Tautan Pembayaran Midtrans</h3>
            <button onclick="document.getElementById('md_link_modal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 space-y-3" id="md_link_body">
            <div class="text-center text-sm text-gray-500 py-6">Memuat...</div>
        </div>
    </div>
</div>

<div id="md_qris_modal" class="fixed inset-0 z-[10000] hidden bg-black/70 items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-5 py-4 border-b flex justify-between items-center bg-emerald-50">
            <h3 class="font-bold text-emerald-800">Tagih via QRIS</h3>
            <button onclick="window._midtransCloseQris()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 text-center" id="md_qris_body">
            <div class="text-sm text-gray-500 py-6">Memuat QRIS...</div>
        </div>
    </div>
</div>

<script>
(function() {
    const linkModal = document.getElementById('md_link_modal');
    const linkBody = document.getElementById('md_link_body');
    const qrisModal = document.getElementById('md_qris_modal');
    const qrisBody = document.getElementById('md_qris_body');
    let pollTimer = null;

    function showLink(invoiceId) {
        linkModal.classList.remove('hidden');
        linkModal.classList.add('flex');
        linkBody.innerHTML = '<div class="text-center text-sm text-gray-500 py-6">Memuat...</div>';

        fetch(`/erp/sales/payment/invoice/${invoiceId}/midtrans-link`, {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
        .then(({ ok, data }) => {
            if (!ok) {
                linkBody.innerHTML = `<div class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">${data.error || 'Gagal'}</div>`;
                return;
            }
            linkBody.innerHTML = `
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">URL</label>
                    <div class="flex gap-2">
                        <input id="md_url" readonly value="${data.url}" class="flex-1 border rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono">
                        <button onclick="navigator.clipboard.writeText(document.getElementById('md_url').value); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Salin',1500)" class="px-3 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700">Salin</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Pesan WhatsApp (siap paste)</label>
                    <textarea id="md_wa" readonly rows="5" class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-50">${data.wa_text}</textarea>
                    <div class="flex gap-2 mt-2">
                        <button onclick="navigator.clipboard.writeText(document.getElementById('md_wa').value); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Salin Teks',1500)" class="flex-1 px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-xs font-bold">Salin Teks</button>
                        <a href="https://wa.me/?text=${encodeURIComponent(data.wa_text)}" target="_blank" rel="noopener" class="flex-1 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-center rounded-lg text-xs font-bold">Buka WA Web</a>
                    </div>
                </div>
                <div class="text-xs text-gray-500">Berlaku hingga ${data.expires_at}</div>
            `;
        })
        .catch(e => {
            linkBody.innerHTML = `<div class="text-sm text-red-600">${e.message}</div>`;
        });
    }

    function loadSnapJs(clientKey, isProduction) {
        return new Promise((resolve, reject) => {
            if (window.snap) return resolve();
            const src = isProduction
                ? 'https://app.midtrans.com/snap/snap.js'
                : 'https://app.sandbox.midtrans.com/snap/snap.js';
            const existing = document.querySelector(`script[src="${src}"]`);
            if (existing) {
                existing.addEventListener('load', () => resolve());
                existing.addEventListener('error', () => reject(new Error('Gagal load snap.js')));
                return;
            }
            const s = document.createElement('script');
            s.src = src;
            s.setAttribute('data-client-key', clientKey);
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Gagal load snap.js'));
            document.head.appendChild(s);
        });
    }

    function showQris(invoiceId) { return openQris(`/erp/sales/payment/invoice/${invoiceId}/midtrans-qris`); }
    function showSoQris(soId)    { return openQris(`/erp/sales/payment/sales-order/${soId}/midtrans-qris`); }

    function openQris(url) {
        qrisModal.classList.remove('hidden');
        qrisModal.classList.add('flex');
        qrisBody.innerHTML = '<div class="text-sm text-gray-500 py-6">Memuat QRIS...</div>';

        fetch(url, {
            headers: { 'Accept': 'application/json' }
        })
        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
        .then(({ ok, data }) => {
            if (!ok) {
                qrisBody.innerHTML = `<div class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">${data.error || 'Gagal'}</div>`;
                return;
            }
            qrisBody.innerHTML = `
                <div class="text-2xl font-bold text-emerald-700 mb-3">Rp ${Number(data.amount).toLocaleString('id-ID')}</div>
                <div id="md_qris_target" class="text-sm text-gray-500 py-6">Memuat QR code...</div>
                <div class="mt-4 text-xs text-gray-500" id="md_qris_status">Menunggu pembayaran... (auto-refresh)</div>
                <div class="mt-2 text-[10px] text-gray-400">Order ID: ${data.order_id}</div>
            `;
            startPolling(data.poll_url);

            loadSnapJs(data.client_key, data.is_production)
                .then(() => {
                    window.snap.pay(data.snap_token, {
                        onSuccess: () => { /* ditangani polling */ },
                        onPending: () => { /* tetap polling */ },
                        onError: (err) => {
                            const el = document.getElementById('md_qris_status');
                            if (el) el.innerHTML = `<span class="text-red-600">Snap error: ${err?.message || 'unknown'}</span>`;
                        },
                        onClose: () => { /* user tutup popup — biarkan, polling tetap jalan */ },
                    });
                })
                .catch(e => {
                    qrisBody.innerHTML = `<div class="text-sm text-red-600">${e.message}</div>`;
                });
        })
        .catch(e => {
            qrisBody.innerHTML = `<div class="text-sm text-red-600">${e.message}</div>`;
        });
    }

    function startPolling(url) {
        stopPolling();
        pollTimer = setInterval(() => {
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    const el = document.getElementById('md_qris_status');
                    if (d.status === 'settlement') {
                        stopPolling();
                        if (el) el.innerHTML = '<span class="text-emerald-600 font-bold">✓ Pembayaran berhasil!</span>';
                        setTimeout(() => {
                            if (d.redirect_url) location.href = d.redirect_url;
                            else location.reload();
                        }, 1200);
                    } else if (['expire','cancel','deny','failure'].includes(d.status)) {
                        stopPolling();
                        if (el) el.innerHTML = `<span class="text-red-600 font-bold">Pembayaran ${d.status}.</span>`;
                    }
                })
                .catch(() => {});
        }, 3000);
    }

    function stopPolling() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    // Batas minimal DP tidak lagi dikirim dari sini — sumbernya kesepakatan yang
    // tercatat di Sales Order (lihat form & halaman SO).
    function showSoLink(soId) {
        linkModal.classList.remove('hidden');
        linkModal.classList.add('flex');
        linkBody.innerHTML = '<div class="text-center text-sm text-gray-500 py-6">Memuat...</div>';

        const url = `/erp/sales/payment/sales-order/${soId}/midtrans-link`;

        fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
        .then(({ ok, data }) => {
            if (!ok) {
                linkBody.innerHTML = `<div class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">${data.error || 'Gagal'}</div>`;
                return;
            }
            linkBody.innerHTML = `
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">URL Link DP</label>
                    <div class="flex gap-2">
                        <input id="md_url" readonly value="${data.url}" class="flex-1 border rounded-lg px-3 py-2 text-sm bg-gray-50 font-mono">
                        <button onclick="navigator.clipboard.writeText(document.getElementById('md_url').value); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Salin',1500)" class="px-3 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700">Salin</button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Pesan WhatsApp (siap paste)</label>
                    <textarea id="md_wa" readonly rows="5" class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-50">${data.wa_text}</textarea>
                    <div class="flex gap-2 mt-2">
                        <button onclick="navigator.clipboard.writeText(document.getElementById('md_wa').value); this.textContent='Tersalin!'; setTimeout(()=>this.textContent='Salin Teks',1500)" class="flex-1 px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-xs font-bold">Salin Teks</button>
                        <a href="https://wa.me/?text=${encodeURIComponent(data.wa_text)}" target="_blank" rel="noopener" class="flex-1 px-3 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-center rounded-lg text-xs font-bold">Buka WA Web</a>
                    </div>
                </div>
                <div class="border-t pt-3">
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Minimal DP</label>
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-bold text-gray-800">Rp ${Number(data.min_dp).toLocaleString('id-ID')}</span>
                        <span class="text-[11px] text-gray-500">${String(data.min_dp_percent).replace('.', ',')}% dari total${data.min_dp_custom ? ' &middot; kesepakatan' : ' &middot; bawaan'}</span>
                    </div>
                    <p class="text-[11px] text-gray-400 mt-1">
                        Sisa tagihan Rp ${Number(data.remaining).toLocaleString('id-ID')}.
                        Untuk mengubah batas ini, edit <a href="${data.so_url}" class="text-blue-600 hover:underline font-semibold">Sales Order</a>-nya.
                    </p>
                </div>
                <div class="text-xs text-gray-500">Berlaku hingga ${data.expires_at}</div>
            `;
        })
        .catch(e => {
            linkBody.innerHTML = `<div class="text-sm text-red-600">${e.message}</div>`;
        });
    }

    window._midtransOpen = function(invoiceId, mode) {
        if (mode === 'link') showLink(invoiceId);
        else if (mode === 'qris') showQris(invoiceId);
    };
    window._midtransOpenSo = function(soId) { showSoLink(soId); };
    window._midtransOpenSoQris = function(soId) { showSoQris(soId); };
    window._midtransCloseQris = function() {
        stopPolling();
        qrisModal.classList.add('hidden');
        qrisModal.classList.remove('flex');
    };
})();
</script>
