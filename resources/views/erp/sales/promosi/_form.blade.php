@php
    $isEdit = ($promo ?? null) !== null;
    $type        = old('type', $isEdit ? $promo->type : 'item');
    $discType    = old('discount_type', $isEdit ? $promo->discount_type : 'nominal');
    $discValue   = old('discount_value', $isEdit ? $promo->discount_value : '');
    $appliesAll  = (bool) old('applies_to_all', $isEdit ? $promo->applies_to_all : false);
    $isVoucher   = (bool) old('is_voucher', $isEdit ? $promo->is_voucher : false);
    $isActive    = (bool) old('is_active', $isEdit ? $promo->is_active : true);
    $selectedIds = collect(old('product_ids', $isEdit ? $promo->products->pluck('product_id')->all() : []))
                     ->map(fn ($v) => (int) $v)->values();
    $tiers = old('tiers', $isEdit && $promo->tiers->count()
        ? $promo->tiers->map(fn ($t) => ['min_spend' => $t->min_spend, 'discount_type' => $t->discount_type, 'discount_value' => $t->discount_value])->all()
        : [['min_spend' => '', 'discount_type' => 'nominal', 'discount_value' => '']]);
@endphp


<form method="POST" action="{{ $action }}" class="space-y-4">
    @csrf
    @if(($method ?? 'POST') !== 'POST') @method($method) @endif

    {{-- ───────── Umum ───────── --}}
    <div class="bg-white rounded shadow p-4 space-y-3 text-sm">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-500 mb-1">Nama Promo <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="{{ old('name', $isEdit ? $promo->name : '') }}" required
                       class="w-full border rounded px-3 py-2" placeholder="Mis. Diskon Lebaran Frame A4">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tipe Promo <span class="text-red-500">*</span></label>
                <select name="type" id="promo_type" class="w-full border rounded px-3 py-2 bg-white">
                    @foreach($types as $val => $label)
                        <option value="{{ $val }}" @selected($type==$val)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
                <input type="number" name="priority" value="{{ old('priority', $isEdit ? $promo->priority : 0) }}"
                       class="w-full border rounded px-3 py-2" placeholder="0">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Mulai</label>
                <input type="date" name="starts_at" value="{{ old('starts_at', $isEdit && $promo->starts_at ? $promo->starts_at->format('Y-m-d') : '') }}"
                       class="w-full border rounded px-3 py-2">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Selesai</label>
                <input type="date" name="ends_at" value="{{ old('ends_at', $isEdit && $promo->ends_at ? $promo->ends_at->format('Y-m-d') : '') }}"
                       class="w-full border rounded px-3 py-2">
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-5 pt-1">
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" class="w-4 h-4 accent-blue-600" @checked($isActive)>
                <span>Aktif</span>
            </label>
            <label class="inline-flex items-center gap-2 cursor-pointer">
                <input type="hidden" name="is_voucher" value="0">
                <input type="checkbox" name="is_voucher" id="promo_is_voucher" value="1" class="w-4 h-4 accent-blue-600" @checked($isVoucher)>
                <span>Jadikan voucher (pakai kode)</span>
            </label>
            <div id="voucher_code_wrap" class="{{ $isVoucher ? '' : 'hidden' }}">
                <input type="text" name="voucher_code" value="{{ old('voucher_code', $isEdit ? $promo->voucher_code : '') }}"
                       class="border rounded px-3 py-1.5 font-mono uppercase" placeholder="KODEVOUCHER"
                       style="text-transform:uppercase">
            </div>
            <span class="text-[11px] text-gray-400">Kosongkan tanggal = tanpa batas · Tanpa voucher = berlaku otomatis.</span>
        </div>
    </div>

    {{-- ───────── Pengaturan diskon ITEM ───────── --}}
    <div class="bg-white rounded shadow p-4 space-y-3 text-sm promo-panel" data-panel="item" style="{{ $type==='item' ? '' : 'display:none' }}">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Pengaturan Diskon Produk</h2>
        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Jenis Diskon</label>
                <select name="discount_type" id="item_disc_type" class="border rounded px-3 py-2 bg-white">
                    <option value="nominal" @selected($discType==='nominal')>Nominal (Rp / unit)</option>
                    <option value="percent" @selected($discType==='percent')>Persentase (%)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Nilai Diskon</label>
                <input type="text" name="discount_value" id="item_disc_value" value="{{ $discValue }}" class="border rounded px-3 py-2 w-40" placeholder="0">
            </div>
            <label class="inline-flex items-center gap-2 cursor-pointer pb-2">
                <input type="hidden" name="applies_to_all" value="0">
                <input type="checkbox" name="applies_to_all" id="applies_to_all" value="1" class="w-4 h-4 accent-blue-600" @checked($appliesAll)>
                <span>Berlaku untuk <b>semua produk</b> (tak perlu pilih)</span>
            </label>
            <span class="text-[11px] text-gray-400 pb-2">Nominal dihitung <b>per unit</b> produk.</span>
        </div>
    </div>

    {{-- ───────── Panel TIER (shipping & cart_total) ───────── --}}
    <div class="bg-white rounded shadow p-4 space-y-3 text-sm promo-panel" data-panel="tier" style="{{ in_array($type, ['shipping','cart_total']) ? '' : 'display:none' }}">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Tingkatan Diskon (berdasarkan total belanja)</h2>
        <p class="text-[11px] text-gray-400">Tier yang dipakai = ambang (min belanja) tertinggi yang terpenuhi. Nominal = potongan flat.</p>
        <div id="tier_rows" class="space-y-2">
            @foreach($tiers as $i => $t)
                <div class="tier-row flex items-end gap-2">
                    <div class="flex-1 max-w-xs">
                        <label class="block text-[11px] text-gray-500 mb-1">Min. Belanja (Rp)</label>
                        <input type="text" name="tiers[{{ $i }}][min_spend]" value="{{ $t['min_spend'] ?? '' }}" class="w-full border rounded px-3 py-2" placeholder="50000">
                    </div>
                    <div class="w-32">
                        <label class="block text-[11px] text-gray-500 mb-1">Jenis</label>
                        <select name="tiers[{{ $i }}][discount_type]" class="w-full border rounded px-2 py-2 bg-white">
                            <option value="nominal" @selected(($t['discount_type'] ?? 'nominal')==='nominal')>Nominal</option>
                            <option value="percent" @selected(($t['discount_type'] ?? '')==='percent')>Persen</option>
                        </select>
                    </div>
                    <div class="w-32">
                        <label class="block text-[11px] text-gray-500 mb-1">Nilai</label>
                        <input type="text" name="tiers[{{ $i }}][discount_value]" value="{{ $t['discount_value'] ?? '' }}" class="w-full border rounded px-3 py-2" placeholder="5000">
                    </div>
                    <button type="button" class="tier-remove text-red-600 hover:bg-red-50 rounded px-2 py-2" title="Hapus">&times;</button>
                </div>
            @endforeach
        </div>
        <button type="button" id="tier_add" class="text-blue-600 hover:underline text-sm font-semibold">+ Tambah tingkat</button>
    </div>

    {{-- ───────── Pilih Produk (di bawah, pola add item) ───────── --}}
    <div class="bg-white rounded shadow p-4 space-y-3 text-sm promo-panel" data-panel="item-products" style="{{ ($type==='item' && !$appliesAll) ? '' : 'display:none' }}">
        <div class="flex items-center justify-between">
            <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Produk Sasaran &amp; Pratinjau Harga</h2>
            <span class="text-xs text-gray-500"><span id="sel_count">0</span> produk dipilih</span>
        </div>

        {{-- Tambah produk (dropdown, bisa pilih banyak) --}}
        <div class="relative" id="add_wrap">
            <div class="flex gap-2">
                <input type="text" id="add_search" autocomplete="off" placeholder="Cari produk / SKU untuk ditambahkan…"
                       class="flex-1 border rounded px-3 py-2">
                <button type="button" id="add_toggle" class="bg-blue-600 hover:bg-blue-700 text-white px-4 rounded text-sm font-semibold whitespace-nowrap">+ Tambah Produk</button>
            </div>
            <div id="add_panel" class="hidden absolute z-30 left-0 right-0 bg-white border rounded-lg shadow-lg max-h-72 overflow-y-auto mt-1"></div>
        </div>

        <div class="overflow-x-auto border rounded">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 text-[11px] uppercase tracking-wide">
                    <tr>
                        <th class="text-left px-3 py-2 w-32">SKU</th>
                        <th class="text-left px-3 py-2">Nama Produk</th>
                        <th class="text-right px-3 py-2 w-32">Harga</th>
                        <th class="text-right px-3 py-2 w-28">Diskon</th>
                        <th class="text-right px-3 py-2 w-32">Harga Setelah</th>
                        <th class="w-8"></th>
                    </tr>
                </thead>
                <tbody id="prod_tbody"></tbody>
            </table>
        </div>
        <p id="prod_empty" class="text-[11px] text-gray-400">Belum ada produk. Klik "+ Tambah Produk" untuk memilih (bisa banyak sekaligus).</p>
    </div>

    <div class="flex gap-2 justify-end">
        <a href="{{ route('sales.promosi.index') }}" class="px-5 py-2 rounded text-sm border border-gray-300 text-gray-600 hover:bg-gray-50">Batal</a>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-semibold">Simpan</button>
    </div>
</form>

<script>
(function () {
    const PRODUCTS = @json($products);
    const PRESELECTED = @json($selectedIds);
    const byId = {}; PRODUCTS.forEach(p => byId[p.id] = p);
    const added = new Map(); // id -> product

    const typeSel = document.getElementById('promo_type');
    const allChk = document.getElementById('applies_to_all');
    const panels = document.querySelectorAll('.promo-panel');
    const discTypeEl = document.getElementById('item_disc_type');
    const discValEl = document.getElementById('item_disc_value');

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const rupiah = n => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
    const num = v => parseFloat(String(v ?? '').replace(/[^\d.-]/g, '')) || 0;

    // ── Tampilkan panel sesuai tipe ──
    function syncLayout() {
        const t = typeSel.value;
        const isItem = t === 'item';
        panels.forEach(p => {
            const w = p.dataset.panel;
            let show = false;
            if (w === 'item') show = isItem;
            else if (w === 'tier') show = (t === 'shipping' || t === 'cart_total');
            else if (w === 'item-products') show = isItem && !allChk.checked;
            p.style.display = show ? '' : 'none';
        });
    }
    typeSel.addEventListener('change', syncLayout);
    allChk.addEventListener('change', syncLayout);
    syncLayout();

    // Voucher toggle
    const vChk = document.getElementById('promo_is_voucher');
    const vWrap = document.getElementById('voucher_code_wrap');
    vChk.addEventListener('change', () => vWrap.classList.toggle('hidden', !vChk.checked));

    // ── Tabel produk + pratinjau diskon ──
    function discountFor(price) {
        const dt = discTypeEl.value;
        const dv = num(discValEl.value);
        const d = dt === 'percent' ? price * dv / 100 : dv;
        return Math.min(price, Math.max(0, d));
    }

    function renderTable() {
        const tbody = document.getElementById('prod_tbody');
        document.getElementById('sel_count').textContent = added.size;
        document.getElementById('prod_empty').style.display = added.size ? 'none' : '';
        const rows = [];
        added.forEach(p => {
            const disc = discountFor(p.price);
            const after = Math.max(0, p.price - disc);
            rows.push(`<tr class="border-t" data-id="${p.id}">
                <td class="px-3 py-2 font-mono text-[11px] text-gray-500">${esc(p.sku || '-')}<input type="hidden" name="product_ids[]" value="${p.id}"></td>
                <td class="px-3 py-2">${esc(p.name)}</td>
                <td class="px-3 py-2 text-right text-gray-500">${rupiah(p.price)}</td>
                <td class="px-3 py-2 text-right text-rose-600">- ${rupiah(disc)}</td>
                <td class="px-3 py-2 text-right font-semibold text-gray-800">${rupiah(after)}</td>
                <td class="px-3 py-2 text-center"><button type="button" class="row-del text-gray-300 hover:text-red-500" title="Hapus">&times;</button></td>
            </tr>`);
        });
        tbody.innerHTML = rows.join('');
    }
    document.getElementById('prod_tbody').addEventListener('click', function (e) {
        const btn = e.target.closest('.row-del');
        if (!btn) return;
        const id = parseInt(btn.closest('tr').dataset.id, 10);
        added.delete(id);
        renderTable();
        if (!document.getElementById('add_panel').classList.contains('hidden')) renderPanel();
    });

    // Recompute pratinjau saat nilai/jenis diskon berubah
    discTypeEl.addEventListener('change', renderTable);
    discValEl.addEventListener('input', renderTable);

    // ── Dropdown tambah produk ──
    const addPanel = document.getElementById('add_panel');
    const addSearch = document.getElementById('add_search');

    function renderPanel() {
        const q = addSearch.value.toLowerCase().trim();
        const avail = PRODUCTS.filter(p => !added.has(p.id) &&
            (!q || (p.name + ' ' + (p.sku || '')).toLowerCase().includes(q)));
        addPanel.innerHTML = avail.length
            ? avail.slice(0, 200).map(p => `<div class="add-opt px-3 py-1.5 hover:bg-indigo-50 cursor-pointer flex items-center gap-2" data-id="${p.id}">
                    <span class="font-mono text-[11px] text-gray-400">${esc(p.sku || '-')}</span>
                    <span class="flex-1">${esc(p.name)}</span>
                    <span class="text-xs text-gray-500">${rupiah(p.price)}</span>
                </div>`).join('')
            : '<div class="px-3 py-2 text-gray-400 text-xs">Tidak ada produk.</div>';
    }
    function openPanel() { renderPanel(); addPanel.classList.remove('hidden'); }
    function closePanel() { addPanel.classList.add('hidden'); }

    document.getElementById('add_toggle').addEventListener('click', function () {
        if (addPanel.classList.contains('hidden')) { openPanel(); addSearch.focus(); } else closePanel();
    });
    addSearch.addEventListener('focus', openPanel);
    addSearch.addEventListener('input', function () { if (addPanel.classList.contains('hidden')) openPanel(); else renderPanel(); });
    addPanel.addEventListener('click', function (e) {
        const opt = e.target.closest('.add-opt');
        if (!opt) return;
        const p = byId[opt.dataset.id];
        if (p) { added.set(p.id, p); renderTable(); renderPanel(); } // panel tetap terbuka → bisa tambah banyak
        addSearch.focus();
    });
    document.addEventListener('click', function (e) {
        if (!document.getElementById('add_wrap').contains(e.target)) closePanel();
    });

    // Pre-fill saat edit
    PRESELECTED.forEach(id => { if (byId[id]) added.set(id, byId[id]); });
    renderTable();

    // ── Tier rows add/remove ──
    const rows = document.getElementById('tier_rows');
    let tierIdx = {{ count($tiers) }};
    document.getElementById('tier_add').addEventListener('click', function () {
        const i = tierIdx++;
        const div = document.createElement('div');
        div.className = 'tier-row flex items-end gap-2';
        div.innerHTML = `
            <div class="flex-1 max-w-xs"><label class="block text-[11px] text-gray-500 mb-1">Min. Belanja (Rp)</label>
                <input type="text" name="tiers[${i}][min_spend]" class="w-full border rounded px-3 py-2" placeholder="50000"></div>
            <div class="w-32"><label class="block text-[11px] text-gray-500 mb-1">Jenis</label>
                <select name="tiers[${i}][discount_type]" class="w-full border rounded px-2 py-2 bg-white">
                    <option value="nominal">Nominal</option><option value="percent">Persen</option></select></div>
            <div class="w-32"><label class="block text-[11px] text-gray-500 mb-1">Nilai</label>
                <input type="text" name="tiers[${i}][discount_value]" class="w-full border rounded px-3 py-2" placeholder="5000"></div>
            <button type="button" class="tier-remove text-red-600 hover:bg-red-50 rounded px-2 py-2" title="Hapus">&times;</button>`;
        rows.appendChild(div);
    });
    rows.addEventListener('click', function (e) {
        if (e.target.classList.contains('tier-remove')) {
            const all = rows.querySelectorAll('.tier-row');
            if (all.length > 1) e.target.closest('.tier-row').remove();
            else { e.target.closest('.tier-row').querySelectorAll('input').forEach(i => i.value = ''); }
        }
    });
})();
</script>
