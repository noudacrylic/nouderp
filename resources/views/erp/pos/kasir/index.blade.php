@extends('layouts.erp')

@section('content')
<div class="mb-3 flex items-start justify-between gap-3">
    <div>
        <h1 class="text-lg font-semibold">Kasir</h1>
        <p class="text-xs text-gray-500">Cari produk, tambahkan ke keranjang, lalu Bayar (Cash / QRIS). Faktur + Surat Jalan dibuat otomatis (ambil di toko).</p>
    </div>
    <a href="{{ route('sales.invoices.index') }}"
       class="shrink-0 text-sm px-3 py-2 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold"
       title="Buka daftar faktur untuk cetak ulang invoice transaksi lalu">🧾 Daftar Faktur</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-12 gap-4">
    {{-- ───────── Kiri: katalog produk ───────── --}}
    <div class="lg:col-span-7">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3">
            <input id="posSearch" type="text" autocomplete="off" placeholder="Cari nama produk / SKU…"
                   class="w-full border rounded-lg px-3 py-2 text-sm mb-3">
            <div id="posResults" class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-[70vh] overflow-y-auto">
                <div class="text-sm text-gray-400 p-4 col-span-full text-center">Memuat produk…</div>
            </div>
        </div>
    </div>

    {{-- ───────── Kanan: keranjang + pembayaran ───────── --}}
    <div class="lg:col-span-5">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-3 sticky top-3">
            <div class="flex items-center gap-2 mb-2">
                <label class="text-xs text-gray-500 shrink-0">Pelanggan</label>
                <select id="posCustomer" class="flex-1 border rounded-lg px-2 py-1.5 text-sm">
                    @foreach($customers as $c)
                        <option value="{{ $c->id }}" {{ $c->id == $defaultCustomer->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
                <button type="button" id="btnAddCust" title="Tambah pelanggan baru"
                        class="shrink-0 px-2.5 py-1.5 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm font-bold">＋</button>
            </div>

            <div class="border border-gray-100 rounded-lg overflow-hidden">
                <div class="flex items-center bg-gray-50 text-gray-500 px-2 py-1.5 text-[10px] font-semibold uppercase tracking-wide">
                    <span class="flex-1">Item</span>
                    <span class="w-20 text-right">Subtotal</span>
                    <span class="w-5"></span>
                </div>
                <div id="posCart" class="divide-y divide-gray-100 max-h-[38vh] overflow-y-auto">
                    <div class="text-sm text-gray-400 p-4 text-center">Keranjang kosong.</div>
                </div>
            </div>

            {{-- Ringkasan --}}
            <div class="mt-3 space-y-1.5 text-sm">
                <div class="flex justify-between"><span class="text-gray-500">Subtotal</span><span id="sumSubtotal" class="font-semibold">Rp 0</span></div>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-gray-500">Voucher</span>
                    <div class="flex items-center gap-1">
                        <input id="voucherInput" type="text" class="border rounded px-2 py-0.5 text-xs w-28 uppercase" placeholder="Kode promo" style="text-transform:uppercase">
                        <button type="button" id="voucherApply" class="text-xs px-2 py-0.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white font-semibold">Pakai</button>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-gray-500">Diskon global</span>
                    <div class="flex items-center gap-1">
                        <select id="gdiscType" class="border rounded px-1 py-0.5 text-xs">
                            <option value="nominal">Rp</option>
                            <option value="percent">%</option>
                        </select>
                        <input id="gdiscValue" type="number" min="0" step="any" value="0" class="border rounded px-2 py-0.5 text-xs w-24 text-right">
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <span class="text-gray-500">PPN %</span>
                    <input id="ppnPercent" type="number" min="0" step="any" value="0" class="border rounded px-2 py-0.5 text-xs w-24 text-right">
                </div>
                <div class="flex justify-between border-t border-gray-100 pt-1.5 text-base">
                    <span class="font-bold">Total</span><span id="sumGrand" class="font-bold text-indigo-700">Rp 0</span>
                </div>
            </div>

            {{-- Aksi bayar. QRIS hanya muncul bila Midtrans sudah terkonfigurasi (lihat controller). --}}
            <div class="mt-3 grid @if($qrisEnabled) grid-cols-2 @else grid-cols-1 @endif gap-2">
                <button id="btnCash" type="button" class="px-3 py-2.5 rounded-lg text-sm font-bold bg-green-600 hover:bg-green-700 text-white">💵 Bayar Cash</button>
                @if($qrisEnabled)
                <button id="btnQris" type="button" class="px-3 py-2.5 rounded-lg text-sm font-bold bg-emerald-600 hover:bg-emerald-700 text-white">📲 Bayar QRIS</button>
                @endif
            </div>

            {{-- Terkunci: ada transaksi QRIS berjalan yang belum dibayar. --}}
            <div id="lockedBar" class="hidden mt-2 space-y-2">
                <div class="text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-3 py-2">
                    Transaksi <b id="lockedInv"></b> menunggu pembayaran QRIS. Selesaikan pembayaran, atau buat transaksi baru — transaksi ini akan <b>dibatalkan</b>.
                </div>
                <button id="btnNewTx" type="button" class="w-full px-3 py-2.5 rounded-lg text-sm font-bold border-2 border-red-300 text-red-700 hover:bg-red-50">➕ Buat Transaksi Baru (batalkan yang ini)</button>
            </div>

            <p id="posError" class="hidden text-xs text-red-600 mt-2"></p>
        </div>
    </div>
</div>

{{-- ───────── Modal Tambah Customer ───────── --}}
<div id="custModal" class="fixed inset-0 z-[10000] hidden bg-black/50 items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-5 py-3 border-b flex justify-between items-center bg-indigo-50">
            <h3 class="font-bold text-indigo-800">Tambah Pelanggan</h3>
            <button onclick="posCloseCust()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 space-y-3">
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Nama <span class="text-red-500">*</span></label>
                <input id="custName" type="text" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="Nama pelanggan">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">No. HP</label>
                <input id="custPhone" type="text" class="w-full border rounded-lg px-3 py-2 text-sm" placeholder="08xxxx (opsional)">
            </div>
            <button id="custSubmit" type="button" class="w-full px-3 py-2.5 rounded-lg text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white disabled:bg-gray-300">Simpan & Pilih</button>
        </div>
    </div>
</div>

{{-- ───────── Modal Cash ───────── --}}
<div id="cashModal" class="fixed inset-0 z-[10000] hidden bg-black/50 items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-5 py-3 border-b flex justify-between items-center bg-green-50">
            <h3 class="font-bold text-green-800">Pembayaran Tunai</h3>
            <button onclick="posCloseCash()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 space-y-3">
            <div class="flex justify-between text-sm"><span class="text-gray-500">Total</span><span id="cashTotal" class="font-bold text-lg">Rp 0</span></div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Akun Kas</label>
                <select id="cashAccount" class="w-full border rounded-lg px-3 py-2 text-sm">
                    @foreach($cashAccounts as $a)
                        <option value="{{ $a->id }}">{{ $a->code }} — {{ $a->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">Uang Diterima</label>
                <input id="cashTendered" type="text" inputmode="numeric" class="rupiah-input w-full border rounded-lg px-3 py-2 text-lg text-right font-bold">
                <div class="flex gap-1.5 mt-1.5" id="cashQuick"></div>
            </div>
            <div class="flex justify-between text-sm bg-gray-50 rounded-lg px-3 py-2">
                <span class="text-gray-500">Kembalian</span><span id="cashChange" class="font-bold text-green-700">Rp 0</span>
            </div>
            <button id="cashSubmit" type="button" class="w-full px-3 py-2.5 rounded-lg text-sm font-bold bg-green-600 hover:bg-green-700 text-white disabled:bg-gray-300">Selesai & Bayar</button>
        </div>
    </div>
</div>

{{-- ───────── Modal QRIS ───────── --}}
<div id="qrisModal" class="fixed inset-0 z-[10000] hidden bg-black/60 items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-5 py-3 border-b flex justify-between items-center bg-emerald-50">
            <h3 class="font-bold text-emerald-800">Pembayaran QRIS</h3>
            <button onclick="posCloseQris()" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="p-5 text-center" id="qrisBody">
            <div class="text-sm text-gray-500 py-6">Memuat QRIS…</div>
        </div>
    </div>
</div>

{{-- ───────── Modal Sukses ───────── --}}
<div id="doneModal" class="fixed inset-0 z-[10000] hidden bg-black/50 items-center justify-center p-4">
    <div class="bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden text-center">
        <div class="p-6">
            <div class="text-4xl mb-2">✅</div>
            <h3 class="font-bold text-lg text-gray-800">Transaksi Selesai</h3>
            <p id="doneInfo" class="text-sm text-gray-500 mt-1"></p>
            <div id="doneChangeWrap" class="hidden mt-3 bg-green-50 rounded-lg py-3">
                <div class="text-xs text-green-700 uppercase tracking-wide">Kembalian</div>
                <div id="doneChange" class="text-2xl font-bold text-green-700">Rp 0</div>
            </div>
            <div class="mt-5 grid grid-cols-2 gap-2">
                <a id="donePrint" href="#" class="px-3 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">🖨 Cetak Faktur</a>
                <button type="button" onclick="posNewSale()" class="px-3 py-2 rounded-lg text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white">Transaksi Baru</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const SEARCH_URL = @json(route('pos.kasir.search'));
    const CHECKOUT_URL = @json(route('pos.kasir.checkout'));
    const RESOLVE_URL = @json(route('pos.kasir.promo-resolve'));

    let cart = [];   // {id, sku, name, price, qty, dtype, dval, manual, promoName, origPrice}
    let pendingSale = null;   // faktur QRIS POS yg BELUM dibayar → kunci kasir sampai lunas/void
    let pollTimer = null;
    let manualGlobal = false;   // user mengubah diskon global secara manual → jangan ditimpa promo
    let voucherCode = '';
    let promoTimer = null;

    const rupiah = n => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
    // Format angka polos jadi titik ribuan (untuk value input rupiah-input yang dirender dinamis)
    const rupiah0 = n => (window.formatThousands ? window.formatThousands(String(Math.round(n || 0))) : String(Math.round(n || 0)));
    // Baca nilai input mata uang (titik ribuan) → number
    const num = v => (window.cleanNumber ? window.cleanNumber(v) : (parseFloat(v) || 0));
    const el = id => document.getElementById(id);

    // ---------- Katalog & pencarian ----------
    let searchTimer = null;
    el('posSearch').addEventListener('input', function () {
        clearTimeout(searchTimer);
        const q = this.value.trim();
        searchTimer = setTimeout(() => runSearch(q), 250);
    });

    function runSearch(q) {
        fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(rows => {
                const box = el('posResults');
                if (!rows.length) { box.innerHTML = '<div class="text-sm text-gray-400 p-4 col-span-full text-center">Tidak ada produk.</div>'; return; }
                box.innerHTML = rows.map(p => `
                    <div class="flex flex-col border border-gray-100 rounded-lg p-2.5 hover:bg-indigo-50/50 hover:border-indigo-200 cursor-pointer"
                         onclick='posAdd(${JSON.stringify(p)})'>
                        <div class="flex items-start justify-between gap-1.5">
                            <div class="text-[15px] font-bold text-gray-900 leading-snug">${escapeHtml(p.name)}</div>
                            ${stockBadge(p)}
                        </div>
                        <div class="text-xs text-gray-500 font-medium mt-0.5">${escapeHtml(p.sku || '-')}</div>
                        <div class="flex items-center justify-between mt-1.5">
                            ${p.promo
                              ? `<span><span class="text-xs text-gray-400 line-through mr-1">${rupiah(p.price)}</span><span class="text-sm font-bold text-rose-600">${rupiah(p.promo.final_price)}</span></span>`
                              : `<span class="text-sm font-bold text-indigo-700">${rupiah(p.price)}</span>`}
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded-md border border-indigo-200 text-indigo-600 hover:bg-indigo-100">＋ Tambah</span>
                        </div>
                        ${p.promo ? `<div class="text-[10px] text-rose-500 font-semibold mt-0.5 truncate">🏷️ ${escapeHtml(p.promo.name)}</div>` : ''}
                    </div>`).join('');
            })
            .catch(() => {});
    }

    window.posAdd = function (p) {
        if (pendingSale) { showError('Selesaikan atau batalkan transaksi yang sedang berlangsung dulu.'); return; }
        const found = cart.find(x => x.id === p.id);
        if (found) { found.qty += 1; }
        else {
            const it = { id: p.id, sku: p.sku, name: p.name, price: p.price, qty: 1, dtype: 'nominal', dval: 0, manual: false, promoName: null, origPrice: p.price };
            if (p.promo) { it.dtype = p.promo.discount_type; it.dval = p.promo.discount_value; it.promoName = p.promo.name; }
            cart.push(it);
        }
        renderCart();
        applyCartPromos();
    };

    // Tampilan keranjang READ-ONLY saat ada transaksi QRIS berjalan (terkunci).
    // Sumber item: data server (setelah reload) atau cart lokal (setelah checkout baru).
    function renderLockedCart() {
        const box = el('posCart');
        let items, subtotal, grand;
        if (pendingSale.items) {
            items = pendingSale.items.map(it => ({ name: it.name, sku: it.sku, qty: it.qty, price: it.unit_price, line: it.line_total }));
            subtotal = (pendingSale.subtotal != null) ? pendingSale.subtotal : pendingSale.grand_total;
            grand = pendingSale.grand_total;
        } else {
            items = cart.map(it => ({ name: it.name, sku: it.sku, qty: it.qty, price: it.price, line: lineSubtotal(it) }));
            const t = calcTotals(); subtotal = t.subtotal; grand = t.grand;
        }
        box.innerHTML = items.length ? items.map(it => `
            <div class="p-2 flex items-start gap-2">
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold text-gray-800 leading-tight">${escapeHtml(it.name)}</div>
                    <div class="text-[11px] text-gray-400">${escapeHtml(it.sku || '-')} · ${fmtQty(it.qty)} × ${rupiah0(it.price)}</div>
                </div>
                <div class="w-24 text-right text-sm font-semibold">${rupiah(it.line)}</div>
            </div>`).join('') : '<div class="text-sm text-gray-400 p-4 text-center">Transaksi berjalan.</div>';
        el('sumSubtotal').textContent = rupiah(subtotal);
        el('sumGrand').textContent = rupiah(grand);
    }

    // ---------- Keranjang ----------
    function lineSubtotal(it) {
        const gross = it.qty * it.price;
        const disc = it.dtype === 'percent' ? gross * (it.dval / 100) : it.qty * it.dval;
        return Math.max(0, gross - disc);
    }

    function renderCart() {
        if (pendingSale) { renderLockedCart(); return; }   // transaksi berjalan → tampilan read-only
        const box = el('posCart');
        if (!cart.length) { box.innerHTML = '<div class="text-sm text-gray-400 p-4 text-center">Keranjang kosong.</div>'; recalc(); return; }
        box.innerHTML = cart.map((it, i) => `
            <div class="p-2">
                <div class="flex items-start gap-2">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-semibold text-gray-800 leading-tight">${escapeHtml(it.name)}</div>
                        ${it.promoName ? `<div class="text-[10px] text-rose-500 font-semibold">🏷️ ${escapeHtml(it.promoName)}</div>` : ''}
                        <div class="text-[11px] text-gray-400">${escapeHtml(it.sku || '-')}</div>
                    </div>
                    <div class="w-20 text-right text-sm font-semibold">${rupiah(lineSubtotal(it))}</div>
                    <button type="button" class="w-5 text-gray-300 hover:text-red-500" onclick="posRemove(${i})">&times;</button>
                </div>
                <div class="flex items-center gap-1.5 mt-1.5 flex-wrap">
                    <div class="flex items-center border rounded">
                        <button type="button" class="px-2 text-gray-500 hover:bg-gray-100" onclick="posQty(${i},-1)">−</button>
                        <input type="number" min="0" step="any" value="${it.qty}" class="w-12 text-center text-xs border-0 py-0.5" oninput="posSet(${i},'qty',this.value)">
                        <button type="button" class="px-2 text-gray-500 hover:bg-gray-100" onclick="posQty(${i},1)">+</button>
                    </div>
                    <span class="text-[11px] text-gray-400">×</span>
                    <input type="text" inputmode="numeric" value="${rupiah0(it.price)}" class="rupiah-input w-24 text-right text-xs border rounded px-1.5 py-0.5" oninput="posSet(${i},'price',this.value)" title="Harga satuan">
                    <span class="text-[11px] text-gray-400 ml-auto">Disk</span>
                    <input type="number" min="0" step="any" value="${it.dval}" class="w-16 text-right text-xs border rounded px-1.5 py-0.5" oninput="posSet(${i},'dval',this.value)" title="Diskon (nominal = per unit)">
                    <select class="border rounded px-1 py-0.5 text-xs" onchange="posSet(${i},'dtype',this.value)">
                        <option value="nominal" ${it.dtype==='nominal'?'selected':''}>Rp</option>
                        <option value="percent" ${it.dtype==='percent'?'selected':''}>%</option>
                    </select>
                </div>
            </div>`).join('');
        recalc();
    }

    window.posRemove = i => { cart.splice(i, 1); renderCart(); applyCartPromos(); };
    window.posQty = (i, d) => { cart[i].qty = Math.max(0, (cart[i].qty || 0) + d); if (cart[i].qty === 0) cart.splice(i,1); renderCart(); applyCartPromos(); };
    window.posSet = (i, f, v) => {
        if (f === 'dtype') { cart[i].dtype = v; cart[i].manual = true; cart[i].promoName = null; }
        else if (f === 'dval') { cart[i].dval = parseFloat(v) || 0; cart[i].manual = true; cart[i].promoName = null; }
        else if (f === 'price') cart[i][f] = num(v);   // harga satuan: input rupiah-input (titik ribuan)
        else cart[i][f] = parseFloat(v) || 0;          // qty (bukan mata uang)
        renderCart();
        if (f === 'qty' || f === 'price') applyCartPromos(); // subtotal berubah → cek tier cart_total
    };

    // ---------- Ringkasan ----------
    el('gdiscType').addEventListener('change', () => { manualGlobal = true; recalc(); });
    el('gdiscValue').addEventListener('input', () => { manualGlobal = true; recalc(); });
    el('ppnPercent').addEventListener('input', recalc);

    // ---------- Promo (cart-total + voucher + item via voucher) ----------
    function applyCartPromos() { clearTimeout(promoTimer); promoTimer = setTimeout(doApplyCartPromos, 300); }

    function doApplyCartPromos() {
        if (!cart.length) {
            if (!manualGlobal) { el('gdiscType').value = 'nominal'; el('gdiscValue').value = 0; }
            recalc();
            return;
        }
        const subtotal = cart.reduce((s, it) => s + lineSubtotal(it), 0);
        const params = new URLSearchParams();
        params.set('subtotal', subtotal);
        if (voucherCode) params.set('voucher_code', voucherCode);
        cart.forEach((it, i) => {
            params.set(`items[${i}][product_id]`, it.id);
            params.set(`items[${i}][qty]`, it.qty);
            params.set(`items[${i}][unit_price]`, it.price);
        });
        fetch(RESOLVE_URL + '?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                const map = res.item_discounts || {};
                cart.forEach(it => {
                    if (it.manual) return; // hormati diskon manual
                    const m = map[it.id];
                    if (m) { it.dtype = m.discount_type; it.dval = parseFloat(m.discount_value) || 0; it.promoName = m.promotion_name; }
                    else if (it.promoName) { it.dtype = 'nominal'; it.dval = 0; it.promoName = null; } // promo tak lagi berlaku
                });
                if (!manualGlobal) {
                    const ct = res.cart_total;
                    if (ct) { el('gdiscType').value = ct.discount_type; el('gdiscValue').value = ct.discount_value; }
                    else { el('gdiscType').value = 'nominal'; el('gdiscValue').value = 0; }
                }
                renderCart();
            })
            .catch(() => {});
    }

    el('voucherApply').addEventListener('click', function () {
        voucherCode = (el('voucherInput').value || '').trim();
        doApplyCartPromos();
    });

    function calcTotals() {
        const subtotal = cart.reduce((s, it) => s + lineSubtotal(it), 0);
        const gv = parseFloat(el('gdiscValue').value) || 0;
        const gtype = el('gdiscType').value;
        const gdisc = gtype === 'percent' ? subtotal * (gv / 100) : gv;
        const dpp = Math.max(0, subtotal - gdisc);
        const ppn = parseFloat(el('ppnPercent').value) || 0;
        const grand = dpp + dpp * (ppn / 100);
        return { subtotal, grand: Math.round(grand) };
    }

    function recalc() {
        if (pendingSale) { renderLockedCart(); return; }   // terkunci → total mengikuti transaksi berjalan
        const t = calcTotals();
        el('sumSubtotal').textContent = rupiah(t.subtotal);
        el('sumGrand').textContent = rupiah(t.grand);
    }

    // ---------- Checkout ----------
    function buildPayload(method, extra) {
        return Object.assign({
            customer_id: el('posCustomer').value,
            payment_method: method,
            voucher_code: voucherCode || null,
            global_discount_type: el('gdiscType').value,
            global_discount_value: el('gdiscValue').value || 0,
            ppn_percent: el('ppnPercent').value || 0,
            items: cart.map(it => ({
                product_id: it.id, qty: it.qty, unit_price: it.price,
                discount_type: it.dtype, discount_value: it.dval, description: '',
            })),
        }, extra || {});
    }

    function showError(msg) { const e = el('posError'); e.textContent = msg; e.classList.remove('hidden'); }
    function clearError() { el('posError').classList.add('hidden'); }

    function postCheckout(payload) {
        return fetch(CHECKOUT_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify(payload),
        }).then(r => r.json().then(d => ({ ok: r.ok, data: d })));
    }

    // ----- Cash -----
    el('btnCash').addEventListener('click', function () {
        clearError();
        if (!cart.length) return showError('Keranjang masih kosong.');
        const t = calcTotals();
        el('cashTotal').textContent = rupiah(t.grand);
        el('cashTendered').value = '';
        el('cashChange').textContent = rupiah(0);
        buildCashQuick(t.grand);
        showModal('cashModal');
        el('cashTendered').focus();
    });
    el('cashTendered').addEventListener('input', updateChange);
    function updateChange() {
        const t = calcTotals();
        const change = num(el('cashTendered').value) - t.grand;
        el('cashChange').textContent = rupiah(Math.max(0, change));
        el('cashChange').classList.toggle('text-red-500', change < 0);
        el('cashChange').classList.toggle('text-green-700', change >= 0);
    }
    function buildCashQuick(grand) {
        const opts = [grand, Math.ceil(grand/50000)*50000, Math.ceil(grand/100000)*100000];
        const uniq = [...new Set(opts)].filter(v => v > 0).slice(0, 3);
        el('cashQuick').innerHTML = uniq.map(v =>
            `<button type="button" class="flex-1 text-xs px-2 py-1 rounded border border-gray-300 hover:bg-gray-50" onclick="document.getElementById('cashTendered').value=${v};document.getElementById('cashTendered').dispatchEvent(new Event('input'))">${rupiah(v)}</button>`
        ).join('');
    }
    el('cashSubmit').addEventListener('click', function () {
        const t = calcTotals();
        const tendered = num(el('cashTendered').value);
        if (tendered + 0.01 < t.grand) { alert('Uang diterima kurang dari total.'); return; }
        this.disabled = true; this.textContent = 'Memproses…';
        postCheckout(buildPayload('cash', { cash_account_id: el('cashAccount').value, amount_tendered: tendered }))
            .then(({ ok, data }) => {
                if (!ok) { alert(data.message || 'Gagal.'); return; }
                posCloseCash();
                showDone(data, data.change);
            })
            .catch(() => alert('Gagal menghubungi server.'))
            .finally(() => { this.disabled = false; this.textContent = 'Selesai & Bayar'; });
    });

    // ----- QRIS ----- (tombol hanya ada bila Midtrans terkonfigurasi)
    el('btnQris')?.addEventListener('click', function () {
        clearError();

        // Sudah ada transaksi berjalan → JANGAN buat faktur baru; tampilkan lagi QRIS-nya.
        if (pendingSale) { openQris(pendingSale); return; }

        if (!cart.length) return showError('Keranjang masih kosong.');
        this.disabled = true;
        postCheckout(buildPayload('qris'))
            .then(({ ok, data }) => {
                if (!ok) { showError(data.message || 'Gagal.'); return; }
                pendingSale = data;   // kunci kasir ke faktur ini sampai lunas / dibatalkan
                setLocked(true);
                openQris(data);
            })
            .catch(() => showError('Gagal menghubungi server.'))
            .finally(() => { this.disabled = false; });
    });

    // Batalkan transaksi berjalan (void faktur + SJ + stok) → boleh mulai transaksi baru.
    el('btnNewTx')?.addEventListener('click', function () {
        if (!pendingSale) return;
        if (!confirm('Transaksi ' + (pendingSale.invoice_no || '') + ' akan DIBATALKAN (faktur & surat jalan di-void, stok dikembalikan). Lanjutkan?')) return;
        this.disabled = true; this.textContent = 'Membatalkan…';
        fetch(@json(route('pos.kasir.void-pending')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
            body: JSON.stringify({ invoice_id: pendingSale.invoice_id }),
        })
            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
            .then(({ ok, data }) => {
                if (!ok) { alert(data.message || 'Gagal membatalkan.'); return; }
                posNewSale();   // reset cart + pendingSale + buka kunci
            })
            .catch(() => alert('Gagal menghubungi server.'))
            .finally(() => { this.disabled = false; this.textContent = '➕ Buat Transaksi Baru (batalkan yang ini)'; });
    });

    function openQris(checkout) {
        showModal('qrisModal');
        el('qrisBody').innerHTML = '<div class="text-sm text-gray-500 py-6">Memuat QRIS…</div>';
        fetch(checkout.qris_url, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
            .then(({ ok, data }) => {
                // `error` = pesan bisnis dari MidtransAdminController; `message` = pesan
                // framework (403 akses menu, 419 sesi kedaluwarsa). Tampilkan keduanya —
                // "Gagal" polos menyembunyikan penyebab sebenarnya.
                if (!ok) { el('qrisBody').innerHTML = `<div class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3">${escapeHtml(data.error || data.message || 'Gagal memuat QRIS.')}</div>`; return; }
                el('qrisBody').innerHTML = `
                    <div class="text-2xl font-bold text-emerald-700 mb-2">${rupiah(data.amount)}</div>
                    <div class="text-xs text-gray-400 mb-3">Faktur ${escapeHtml(checkout.invoice_no)}</div>
                    <div id="qrisStatus" class="text-sm text-amber-600 font-semibold py-4">⏳ Menunggu pembayaran…</div>
                    <div class="text-[10px] text-gray-400">Order ID: ${data.order_id}</div>`;
                startPolling(data.poll_url, checkout);
                loadSnapJs(data.client_key, data.is_production)
                    .then(() => window.snap.pay(data.snap_token, { onError: () => {}, onClose: () => {} }))
                    .catch(() => {});
            });
    }

    function startPolling(url, checkout) {
        stopPolling();
        pollTimer = setInterval(() => {
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(d => {
                    const s = el('qrisStatus');
                    if (d.status === 'settlement') {
                        stopPolling();
                        closeSnapPopup(); // tutup popup Midtrans (Snap tak menutup sendiri saat lunas via webhook/simulasi)
                        pendingSale = null; setLocked(false);   // lunas → buka kunci kasir
                        if (s) s.outerHTML = '<div class="text-emerald-600 font-bold text-lg py-4">✓ Pembayaran diterima!</div>';
                        setTimeout(() => { posCloseQris(); showDone(checkout, null); }, 1200);
                    } else if (['expire', 'cancel', 'deny', 'failure'].includes(d.status)) {
                        stopPolling();
                        if (s) { s.textContent = 'Pembayaran ' + d.status + '.'; s.className = 'text-sm text-red-600 font-semibold py-4'; }
                    }
                }).catch(() => {});
        }, 3000);
    }
    function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }

    // Tutup paksa popup Snap Midtrans. Saat pembayaran tuntas via webhook (atau simulasi
    // sandbox), Snap tidak selalu menutup sendiri — jadi kita bereskan overlay-nya.
    function closeSnapPopup() {
        try { if (window.snap && typeof window.snap.hide === 'function') window.snap.hide(); } catch (e) {}
        // Snap menyuntik overlay sebagai anak langsung <body>; naik dari iframe-nya
        // sampai level body lalu buang seluruh kontainernya.
        document.querySelectorAll('iframe[src*="midtrans"]').forEach(f => {
            let node = f;
            while (node.parentElement && node.parentElement !== document.body) node = node.parentElement;
            node.remove();
        });
        document.querySelectorAll('[id*="snap"], [class*="snap"]').forEach(e => {
            if (e.querySelector && e.querySelector('iframe[src*="midtrans"]')) e.remove();
        });
        document.body.style.overflow = '';
    }

    function loadSnapJs(clientKey, isProd) {
        return new Promise((resolve, reject) => {
            if (window.snap) return resolve();
            const src = isProd ? 'https://app.midtrans.com/snap/snap.js' : 'https://app.sandbox.midtrans.com/snap/snap.js';
            const ex = document.querySelector(`script[src="${src}"]`);
            if (ex) { ex.addEventListener('load', () => resolve()); ex.addEventListener('error', () => reject()); return; }
            const s = document.createElement('script');
            s.src = src; s.setAttribute('data-client-key', clientKey);
            s.onload = () => resolve(); s.onerror = () => reject();
            document.head.appendChild(s);
        });
    }

    // ---------- Sukses ----------
    function showDone(data, change) {
        el('doneInfo').textContent = 'Faktur ' + (data.invoice_no || '') + ' · ' + rupiah(data.grand_total);
        el('donePrint').href = data.print_url || '#';
        if (change != null) {
            el('doneChange').textContent = rupiah(change);
            el('doneChangeWrap').classList.remove('hidden');
        } else {
            el('doneChangeWrap').classList.add('hidden');
        }
        showModal('doneModal');
    }

    window.posNewSale = function () {
        pendingSale = null; setLocked(false);   // buka kunci kasir
        cart = [];
        manualGlobal = false; voucherCode = '';
        el('gdiscValue').value = 0; el('gdiscType').value = 'nominal'; el('ppnPercent').value = 0;
        el('voucherInput').value = '';
        el('posSearch').value = '';
        renderCart();
        runSearch('');
        hideModal('doneModal');
    };

    // ---------- Util modal ----------
    function showModal(id) { const m = el(id); m.classList.remove('hidden'); m.classList.add('flex'); }
    function hideModal(id) { const m = el(id); m.classList.add('hidden'); m.classList.remove('flex'); }
    window.posCloseCash = () => hideModal('cashModal');
    // Tutup tampilan QRIS TANPA membatalkan transaksi — kasir tetap terkunci ke faktur
    // berjalan (lihat pendingSale). Operator bisa "Tampilkan QRIS" lagi atau buat transaksi baru.
    window.posCloseQris = () => { stopPolling(); hideModal('qrisModal'); };

    // Kunci/buka kasir saat ada transaksi QRIS berjalan yang belum dibayar.
    function setLocked(locked) {
        const cashBtn = el('btnCash'), qrisBtn = el('btnQris'), bar = el('lockedBar');
        // Input yang dibekukan selama transaksi berjalan.
        ['gdiscValue', 'gdiscType', 'ppnPercent', 'voucherInput', 'posSearch'].forEach(id => {
            const e = el(id); if (e) e.disabled = locked;
        });
        if (locked) {
            if (cashBtn) cashBtn.classList.add('hidden');
            if (qrisBtn) { qrisBtn.textContent = '📲 Tampilkan QRIS'; qrisBtn.classList.add('col-span-2'); }
            if (bar) bar.classList.remove('hidden');
            if (pendingSale && el('lockedInv')) el('lockedInv').textContent = pendingSale.invoice_no || '';
        } else {
            if (cashBtn) cashBtn.classList.remove('hidden');
            if (qrisBtn) { qrisBtn.textContent = '📲 Bayar QRIS'; qrisBtn.classList.remove('col-span-2'); }
            if (bar) bar.classList.add('hidden');
        }
        renderCart();   // beralih antara tampilan read-only ↔ editable
    }

    function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function fmtQty(n) { n = parseFloat(n) || 0; return Number.isInteger(n) ? n : n.toFixed(2).replace(/\.?0+$/, ''); }
    function stockBadge(p) {
        if (!p.tracked) return '<span class="shrink-0 text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-400">tanpa stok</span>';
        const s = p.stock || 0;
        if (s <= 0) return '<span class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-600">Habis</span>';
        return `<span class="shrink-0 text-[11px] font-bold px-2 py-0.5 rounded bg-green-100 text-green-700">Stok ${fmtQty(s)}</span>`;
    }

    // ---------- Customer: live search (TomSelect) + quick add ----------
    let custTom = null;
    if (window.TomSelect) {
        custTom = new TomSelect('#posCustomer', { create: false, maxItems: 1, placeholder: 'Cari pelanggan…', dropdownParent: 'body' });
    }
    el('btnAddCust').addEventListener('click', function () {
        el('custName').value = ''; el('custPhone').value = '';
        showModal('custModal'); el('custName').focus();
    });
    window.posCloseCust = () => hideModal('custModal');
    el('custSubmit').addEventListener('click', function () {
        const name = el('custName').value.trim();
        if (!name) { el('custName').focus(); return; }
        this.disabled = true; this.textContent = 'Menyimpan…';
        fetch('/erp/customers/store-ajax', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ name, phone: el('custPhone').value.trim() || null }),
        })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.id) throw new Error('Gagal');
            if (custTom) { custTom.addOption({ value: d.id, text: d.name }); custTom.refreshOptions(false); custTom.setValue(d.id); }
            else { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; o.selected = true; el('posCustomer').appendChild(o); }
            posCloseCust();
        })
        .catch(() => alert('Gagal menyimpan pelanggan.'))
        .finally(() => { this.disabled = false; this.textContent = 'Simpan & Pilih'; });
    });

    renderCart();
    runSearch('');   // tampilkan produk default saat halaman dibuka (tetap bisa di-search)

    // Pulihkan kunci bila server mendeteksi transaksi QRIS berjalan yang belum dibayar
    // (mis. setelah popup Snap me-redirect atau halaman ter-refresh).
    const SERVER_PENDING = @json($pendingSale ?? null);
    if (SERVER_PENDING) { pendingSale = SERVER_PENDING; setLocked(true); }
})();
</script>
@endsection
