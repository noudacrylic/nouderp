{{-- Integrasi promo untuk form transaksi Sales (Penawaran/SO/Invoice).
     Pola aman: hanya MENGISI field diskon existing lalu memanggil recalculate()/computeNet()
     yang sudah ada. Tidak menyentuh inti perhitungan. Selalu hormati input manual. --}}
<script>
(function () {
    const RESOLVE_URL = @json(route('sales.promosi.resolve'));
    const num = (v) => (window.cleanNumber ? window.cleanNumber(v) : (parseFloat(String(v ?? '').replace(/[^\d.-]/g, '')) || 0));
    const recalc = () => { if (typeof window.recalculate === 'function') window.recalculate(); };

    let promoTimer = null;
    let manualGlobal = false;
    let manualShip = false;
    let voucher = '';
    let progShip = false; // guard saat set shipping secara programatik

    // ── Kumpulkan item dari DOM ──
    function itemsFromDom() {
        const out = [];
        document.querySelectorAll('#items tr.item-row').forEach(row => {
            const pid = parseInt(row.querySelector('.product_id')?.value || 0, 10);
            if (!pid) return;
            out.push({
                product_id: pid,
                qty: num(row.querySelector('.qty')?.value) || 0,
                unit_price: num(row.querySelector('.price')?.value) || 0,
                _row: row,
            });
        });
        return out;
    }

    function subtotalFromDom() {
        let s = 0;
        itemsFromDom().forEach(it => {
            const row = it._row;
            const dv = num(row.querySelector('.discount-value')?.value);
            const dt = row.querySelector('.discount-type')?.value;
            const disc = dt === 'percent' ? (it.qty * it.unit_price * dv / 100) : dv;
            s += Math.max(0, it.qty * it.unit_price - disc);
        });
        return s;
    }

    function shipGross() {
        const el = document.getElementById('shipping_gross_input');
        return el ? num(el.value) : 0;
    }
    function isPickup() {
        return (document.getElementById('delivery_method')?.value || '') === 'ambil_toko';
    }

    function fetchResolve(ctx) {
        const p = new URLSearchParams();
        p.set('subtotal', ctx.subtotal || 0);
        p.set('shipping_gross', ctx.shipping_gross || 0);
        if (ctx.voucher_code) p.set('voucher_code', ctx.voucher_code);
        (ctx.items || []).forEach((it, i) => {
            p.set(`items[${i}][product_id]`, it.product_id);
            p.set(`items[${i}][qty]`, it.qty);
            p.set(`items[${i}][unit_price]`, it.unit_price);
        });
        return fetch(RESOLVE_URL + '?' + p.toString(), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json());
    }

    function applyItemDiscounts(map) {
        document.querySelectorAll('#items tr.item-row').forEach(row => {
            if (row.getAttribute('data-promo-manual') === '1') return;
            const pid = parseInt(row.querySelector('.product_id')?.value || 0, 10);
            if (!pid) return;
            const dvEl = row.querySelector('.discount-value');
            const dtEl = row.querySelector('.discount-type');
            if (!dvEl || !dtEl) return;
            const m = map[pid];
            if (m) {
                dtEl.value = m.discount_type;
                dvEl.value = m.discount_value;
                row.setAttribute('data-promo-applied', '1');
            } else if (row.getAttribute('data-promo-applied') === '1') {
                dvEl.value = 0; // promo tak lagi berlaku
                row.removeAttribute('data-promo-applied');
            }
        });
    }

    function setGlobal(ct) {
        if (manualGlobal) return;
        const typeEl = document.getElementById('globalDiscountType');
        const valEl = document.getElementById('globalDiscount');
        if (!typeEl || !valEl) return;
        if (ct) { typeEl.value = ct.discount_type; valEl.value = ct.discount_value; valEl.dataset.raw = ct.discount_value; }
        else { valEl.value = 0; valEl.dataset.raw = 0; }
    }

    function setShipping(sh) {
        if (manualShip || isPickup()) return;
        const typeEl = document.getElementById('ship_disc_type');
        const valEl = document.getElementById('ship_disc_value');
        if (!typeEl || !valEl) return;
        progShip = true;
        if (sh) { typeEl.value = sh.discount_type; valEl.value = sh.discount_value; }
        else { valEl.value = 0; }
        valEl.dispatchEvent(new Event('input', { bubbles: true })); // memicu computeNet() shipping-embed
        progShip = false;
    }

    function doApply() {
        const items = itemsFromDom().map(({ _row, ...rest }) => rest);
        if (!items.length) { setGlobal(null); recalc(); return; }
        fetchResolve({ items, subtotal: subtotalFromDom(), shipping_gross: shipGross(), voucher_code: voucher })
            .then(res => {
                applyItemDiscounts(res.item_discounts || {});
                setGlobal(res.cart_total);
                setShipping(res.shipping);
                recalc();
            })
            .catch(() => {});
    }
    function scheduleApply() { clearTimeout(promoTimer); promoTimer = setTimeout(doApply, 350); }

    // ── Hook saat produk dipilih (dipanggil dari transaction-form) ──
    window._promoOnPick = function (row) {
        // row = jQuery row; biarkan doApply yang mengisi (sudah berbasis DOM)
        scheduleApply();
    };

    // ── Tandai manual saat user mengubah diskon ──
    $(document).on('input change', '#items .discount-value, #items .discount-type', function () {
        $(this).closest('tr').attr('data-promo-manual', '1').removeAttr('data-promo-applied');
    });
    $(document).on('input change', '#globalDiscount, #globalDiscountType', function () { manualGlobal = true; });
    $(document).on('input change', '#ship_disc_value, #ship_disc_type', function () { if (!progShip) manualShip = true; });

    // Re-resolve saat qty/harga/baris berubah
    $(document).on('input change', '#items .qty, #items .price, #items .unit-select', scheduleApply);
    $(document).on('click', '.remove-item, .btn-remove-item', scheduleApply);

    // ── Voucher input (disisipkan ke ringkasan) ──
    document.addEventListener('DOMContentLoaded', function () {
        const sec = document.querySelector('.summary-section .space-y-3');
        if (sec && !document.getElementById('promoVoucherInput')) {
            const wrap = document.createElement('div');
            wrap.className = 'flex justify-between items-center text-gray-600';
            wrap.innerHTML = `<span>Voucher</span>
                <div class="flex gap-2">
                    <input type="text" id="promoVoucherInput" class="border border-gray-300 rounded px-2 py-1 w-28 uppercase bg-white" placeholder="Kode" style="text-transform:uppercase">
                    <button type="button" id="promoVoucherApply" class="px-3 py-1 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold">Pakai</button>
                </div>`;
            sec.insertBefore(wrap, sec.children[1] || null); // setelah subtotal
            document.getElementById('promoVoucherApply').addEventListener('click', function () {
                voucher = (document.getElementById('promoVoucherInput').value || '').trim();
                doApply();
            });
        }
        // Lindungi diskon yang SUDAH tersimpan (mode edit / konversi quotation) agar
        // tidak ditimpa promo saat resolusi awal. Tandai baris ber-diskon > 0 sebagai
        // manual, dan set manualGlobal/manualShip bila diskon global/ongkir existing > 0.
        // (Baris ber-diskon 0 tetap boleh diisi promo — fitur entry baru tetap jalan.)
        document.querySelectorAll('#items tr.item-row').forEach(row => {
            if (num(row.querySelector('.discount-value')?.value) > 0) {
                row.setAttribute('data-promo-manual', '1');
            }
        });
        if (num(document.getElementById('globalDiscount')?.value) > 0) manualGlobal = true;
        if (num(document.getElementById('ship_disc_value')?.value) > 0) manualShip = true;

        // resolusi awal (mis. saat edit / load dari quotation)
        setTimeout(scheduleApply, 600);
    });
})();
</script>
