@csrf

<style>
    .po-item-row td { vertical-align: middle; padding: 6px 8px; }
    .po-item-row input, .po-item-row select { font-size: 13px; }
    .product-dropdown { position:absolute; left:0; right:0; top:100%; background:#fff; border:1px solid #d1d5db; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:30; max-height:240px; overflow-y:auto; display:none; }
    .product-dropdown.show { display:block; }
    .product-dropdown .item { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6; }
    .product-dropdown .item:hover { background:#eff6ff; }
    .product-dropdown .item .sku { font-family: monospace; font-size:11px; color:#2563eb; font-weight:bold; }
    .product-dropdown .item .name { font-size:13px; color:#1f2937; }
    .summary-row { display:flex; justify-content:space-between; align-items:center; }
    .summary-label { color:#6b7280; font-size:13px; }
    .summary-value { font-size:13px; }
</style>

<!-- HEADER (1 BARIS) -->
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="grid grid-cols-4 gap-3">
        <div>
            <label class="block text-sm mb-1">Pemasok <span class="text-red-500">*</span></label>
            <div class="flex gap-1">
                <div class="relative flex-1">
                    <input type="text" id="supplierSearch" class="border rounded px-3 py-2 w-full" placeholder="Ketik nama / kode pemasok..." autocomplete="off"
                           value="{{ old('supplier_id') ? '' : (isset($po) && $po->supplier ? $po->supplier->name . ' (' . $po->supplier->code . ')' : '') }}" required>
                    <input type="hidden" name="supplier_id" id="supplierId" value="{{ old('supplier_id', $po->supplier_id ?? '') }}">
                    <div id="supplierResults" class="hidden absolute z-30 left-0 right-0 mt-1 bg-white border rounded shadow max-h-60 overflow-y-auto text-sm"></div>
                </div>
                <button type="button" id="btnNewSupplier" class="bg-green-600 text-white px-3 rounded text-sm" title="Tambah pemasok baru">+</button>
            </div>
        </div>
        <div>
            <label class="block text-sm mb-1">Gudang Tujuan <span class="text-red-500">*</span></label>
            @php
                $defaultWarehouseId = isset($po)
                    ? $po->warehouse_id
                    : optional($warehouses->firstWhere('name', 'Utama'))->id;
            @endphp
            <select name="warehouse_id" class="border rounded px-3 py-2 w-full" required>
                <option value="">— pilih —</option>
                @foreach($warehouses as $w)
                    <option value="{{ $w->id }}" @selected(old('warehouse_id', $defaultWarehouseId) == $w->id)>{{ $w->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm mb-1">Tanggal PO <span class="text-red-500">*</span></label>
            <input type="date" name="po_date" class="border rounded px-3 py-2 w-full" value="{{ old('po_date', isset($po) ? $po->po_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
        </div>
        <div>
            <label class="block text-sm mb-1">Tanggal Diharapkan</label>
            <input type="date" name="expected_date" class="border rounded px-3 py-2 w-full" value="{{ old('expected_date', isset($po) && $po->expected_date ? $po->expected_date->format('Y-m-d') : '') }}">
        </div>
        <div>
            <label class="block text-sm mb-1">No. Faktur Pemasok</label>
            <input type="text" name="supplier_invoice_no" class="border rounded px-3 py-2 w-full"
                   value="{{ old('supplier_invoice_no', $po->supplier_invoice_no ?? '') }}"
                   placeholder="No. nota/faktur dari pemasok" autocomplete="off">
            <p class="text-xs text-gray-400 mt-1">Untuk audit &amp; pencocokan nota fisik pemasok.</p>
        </div>
    </div>
</div>

<!-- ITEMS -->
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h3 class="font-semibold">Items</h3>
            <p class="text-xs text-gray-500">Nama produk bisa diedit (untuk pembelian aset/non-katalog, kosongkan SKU & ketik nama langsung).</p>
        </div>
        <button type="button" id="addItem" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">+ Tambah Item</button>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600 text-xs uppercase">
            <tr>
                <th class="px-2 py-2 text-center" style="width:60px;" title="Centang untuk pembelian aset tetap">Aset?</th>
                <th class="px-2 py-2 text-left" style="width:240px;">SKU / Kategori Aset</th>
                <th class="px-2 py-2 text-left">Nama Produk / Aset</th>
                <th class="px-2 py-2 text-right" style="width:80px;">Qty</th>
                <th class="px-2 py-2 text-left" style="width:80px;">Unit</th>
                <th class="px-2 py-2 text-right" style="width:120px;">Harga</th>
                <th class="px-2 py-2 text-right" style="width:160px;">Diskon Line</th>
                <th class="px-2 py-2 text-right" style="width:120px;">Subtotal</th>
                <th style="width:30px;"></th>
            </tr>
        </thead>
        <tbody id="itemsBody"></tbody>
    </table>
</div>

<!-- EXPENSES -->
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h3 class="font-semibold">Biaya Tambahan</h3>
            <p class="text-xs text-gray-500">Mode <b>Dikapitalisasi</b> = nambah HPP barang. Mode <b>Beban Langsung</b> = langsung jadi beban.</p>
        </div>
        <button type="button" id="addExpense" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">+ Tambah Biaya</button>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600 text-xs uppercase">
            <tr>
                <th class="px-2 py-2 text-left">Akun</th>
                <th class="px-2 py-2 text-left">Keterangan</th>
                <th class="px-2 py-2 text-left" style="width:180px;">Mode</th>
                <th class="px-2 py-2 text-right" style="width:140px;">Nominal</th>
                <th style="width:30px;"></th>
            </tr>
        </thead>
        <tbody id="expensesBody"></tbody>
    </table>
</div>

<!-- BOTTOM: CATATAN (kiri) + SUMMARY (kanan) -->
<div class="grid grid-cols-3 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 col-span-2">
        <h3 class="font-semibold mb-2">Catatan</h3>
        <textarea name="notes" rows="8" class="border rounded px-3 py-2 w-full text-sm">{{ old('notes', $po->notes ?? '') }}</textarea>
    </div>
    <div class="border border-gray-100 rounded-xl p-6 bg-gray-50/50 shadow-sm space-y-4">
        <div class="space-y-3 text-sm">
            <!-- Subtotal -->
            <div class="flex justify-between text-gray-600">
                <span>Subtotal</span>
                <span id="sumSubtotal" class="font-medium text-gray-900">0</span>
            </div>

            <!-- Diskon Line (auto, dari item) -->
            <div class="flex justify-between text-gray-600">
                <span>Diskon Line</span>
                <span id="sumLineDisc" class="text-red-600">−0</span>
            </div>

            <!-- Diskon Global -->
            <div class="flex justify-between items-center text-gray-600">
                <span>Diskon Global</span>
                <div class="flex gap-2">
                    @php
                        $defaultGdiscType = isset($po) ? ($po->global_discount_type ?? '') : 'nominal';
                    @endphp
                    <select name="global_discount_type" id="gdiscType"
                        class="border border-gray-300 rounded px-2 py-1 w-20 bg-white">
                        <option value="">—</option>
                        <option value="percent" @selected(old('global_discount_type', $defaultGdiscType) === 'percent')>%</option>
                        <option value="nominal" @selected(old('global_discount_type', $defaultGdiscType) === 'nominal')>Rp</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="global_discount_value" id="gdiscValue"
                        value="{{ old('global_discount_value', $po->global_discount_value ?? 0) }}"
                        class="border border-gray-300 rounded px-2 py-1 w-28 text-right bg-white">
                </div>
            </div>

            <!-- Total Beban -->
            <div class="flex justify-between text-gray-600">
                <span>Total Beban</span>
                <span id="sumExpense" class="font-medium text-gray-900">0</span>
            </div>

            <!-- PPN -->
            <div class="flex justify-between items-center text-gray-600">
                <span>PPN (%)</span>
                <input type="number" step="0.01" min="0" max="100" name="ppn_percent" id="ppnPercent"
                    value="{{ old('ppn_percent', $po->ppn_percent ?? 0) }}"
                    class="border border-gray-300 rounded px-2 py-1 w-28 text-right bg-white">
            </div>

            <div class="flex justify-between text-gray-500 text-xs">
                <span>PPN Nominal</span>
                <span id="sumPpn">0</span>
            </div>

            <hr class="border-gray-200 my-4">

            <!-- Grand Total -->
            <div class="flex justify-between items-center font-bold text-lg text-blue-900">
                <span>GRAND TOTAL</span>
                <span id="sumTotal">0</span>
            </div>
        </div>
    </div>
</div>

<div class="flex gap-2 justify-end">
    <a href="{{ route('purchasing.orders.index') }}" class="px-4 py-2 border rounded text-sm font-bold text-gray-600 hover:bg-gray-50 transition">Batal</a>
    <button type="submit" name="_after_save" value=""
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold transition">
        Simpan
    </button>
    <button type="submit" name="_after_save" value="print"
        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-bold transition">
        Cetak
    </button>
</div>

<!-- MODAL: Tambah Supplier Cepat -->
<div id="modalSupplier" class="fixed inset-0 hidden items-center justify-center" style="background-color: rgba(0,0,0,0.55); z-index: 9999;">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-md p-5" style="margin: 16px;">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold">Tambah Pemasok Baru</h3>
            <button type="button" class="text-gray-400 hover:text-gray-700 text-xl" id="closeModal">&times;</button>
        </div>
        <div id="modalError" class="hidden bg-red-100 border border-red-300 text-red-700 px-3 py-2 rounded text-sm mb-3"></div>
        <div class="space-y-3 text-sm">
            <div>
                <label class="block mb-1">Nama <span class="text-red-500">*</span></label>
                <input type="text" id="newSupName" class="border rounded px-3 py-2 w-full">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block mb-1">Telp</label>
                    <input type="text" id="newSupPhone" class="border rounded px-3 py-2 w-full">
                </div>
                <div>
                    <label class="block mb-1">Kota</label>
                    <input type="text" id="newSupCity" class="border rounded px-3 py-2 w-full">
                </div>
            </div>
            <div>
                <label class="block mb-1">Term Bayar (hari)</label>
                <input type="number" id="newSupTerm" value="30" min="0" class="border rounded px-3 py-2 w-full">
            </div>
        </div>
        <div class="flex justify-end gap-2 mt-5">
            <button type="button" class="px-4 py-2 border rounded text-sm" id="cancelModal">Batal</button>
            <button type="button" class="bg-blue-600 text-white px-4 py-2 rounded text-sm" id="saveModal">Simpan</button>
        </div>
    </div>
</div>

<template id="itemRowTpl">
    <tr class="border-b po-item-row">
        <td class="text-center">
            <input type="hidden" name="items[__I__][is_asset]" value="0" class="is-asset-hidden">
            <input type="checkbox" class="is-asset-chk" title="Centang jika baris ini adalah pembelian aset tetap">
        </td>
        <td>
            <div class="relative product-cell">
                <input type="text" class="border rounded px-2 py-1.5 w-full font-mono text-xs sku-in" placeholder="Cari SKU/nama...">
                <div class="product-dropdown"></div>
                <input type="hidden" name="items[__I__][product_id]" class="product-id">
            </div>
            <div class="asset-cell" style="display:none;">
                <select name="items[__I__][asset_category_id]" class="asset-category-in border rounded px-2 py-1.5 w-full text-xs bg-orange-50">
                    <option value="">— pilih kategori aset —</option>
                    @foreach(($assetCategories ?? []) as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->code }} — {{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
        </td>
        <td><input type="text" name="items[__I__][description]" class="border rounded px-2 py-1.5 w-full desc-in" placeholder="Nama produk / aset" required></td>
        <td><input type="number" step="0.01" min="0.01" name="items[__I__][qty]" class="border rounded px-2 py-1.5 w-full text-right qty-in" required></td>
        <td>
            <select name="items[__I__][unit]" class="border rounded px-2 py-1.5 w-full unit-in bg-white">
                <option value="">—</option>
                <option value="pcs">pcs</option>
                <option value="lusin">lusin</option>
                <option value="box">box</option>
                <option value="set">set</option>
                <option value="lembar">lembar</option>
                <option value="m">m</option>
                <option value="meter">meter</option>
                <option value="kg">kg</option>
                <option value="gram">gram</option>
                <option value="liter">liter</option>
                <option value="rim">rim</option>
                <option value="pak">pak</option>
                <option value="rol">rol</option>
            </select>
        </td>
        <td><input type="text" inputmode="numeric" name="items[__I__][price]" class="rupiah-input border rounded px-2 py-1.5 w-full text-right price-in" required></td>
        <td>
            <div class="flex gap-1">
                <select name="items[__I__][discount_type]" class="border rounded px-1 py-1.5 text-xs disc-type" style="width:50px;">
                    <option value="percent">%</option>
                    <option value="nominal">Rp</option>
                </select>
                <input type="number" step="0.01" min="0" name="items[__I__][discount_value]" class="border rounded px-2 py-1.5 w-full text-right disc-val" value="0">
            </div>
        </td>
        <td class="text-right font-medium line-subtotal">0</td>
        <td><button type="button" class="text-red-600 del-item">×</button></td>
    </tr>
</template>

<template id="expenseRowTpl">
    <tr class="border-b expense-row">
        <td>
            <div class="relative">
                <input type="text" class="border rounded px-2 py-1.5 w-full account-search" placeholder="Cari kode / nama akun..." autocomplete="off" required>
                <input type="hidden" name="expenses[__E__][account_id]" class="account-id">
                <div class="product-dropdown account-dropdown"></div>
            </div>
        </td>
        <td><input type="text" name="expenses[__E__][description]" class="border rounded px-2 py-1.5 w-full"></td>
        <td>
            <select name="expenses[__E__][mode]" class="border rounded px-2 py-1.5 w-full mode-in">
                <option value="capitalized">Dikapitalisasi (HPP)</option>
                <option value="direct_expense">Beban Langsung</option>
            </select>
        </td>
        <td><input type="text" inputmode="numeric" name="expenses[__E__][amount]" class="rupiah-input border rounded px-2 py-1.5 w-full text-right amount-in" required></td>
        <td><button type="button" class="text-red-600 del-expense">×</button></td>
    </tr>
</template>

<script>
(function(){
    function format(n){ return new Intl.NumberFormat('id-ID',{maximumFractionDigits:2}).format(n); }

    // Number cleaner lokal (tidak bergantung urutan load window.cleanNumber, yang
    // didefinisikan di layout SETELAH script ini → recalc awal pernah throw & summary macet di 0).
    function cleanNum(v){
        if (v === null || v === undefined) return 0;
        var s = String(v).replace(/\./g, '').replace(/[^0-9,\-]/g, '').replace(',', '.');
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }

    // ===== SUPPLIER SEARCH =====
    const supSearch = document.getElementById('supplierSearch');
    const supId = document.getElementById('supplierId');
    const supResults = document.getElementById('supplierResults');
    let supTimer = null;

    supSearch.addEventListener('input', () => {
        supId.value = '';
        clearTimeout(supTimer);
        const q = supSearch.value.trim();
        if (!q) { supResults.classList.add('hidden'); return; }
        supTimer = setTimeout(() => doSupSearch(q), 250);
    });
    supSearch.addEventListener('focus', () => { if (supSearch.value.trim()) doSupSearch(supSearch.value.trim()); });
    document.addEventListener('click', e => {
        if (!supResults.contains(e.target) && e.target !== supSearch) supResults.classList.add('hidden');
    });
    async function doSupSearch(q){
        try {
            const res = await fetch(`/erp/purchasing/api/suppliers/search?q=${encodeURIComponent(q)}`);
            renderSupResults(await res.json());
        } catch(e){ console.error(e); }
    }
    function renderSupResults(list){
        supResults.innerHTML = '';
        if (!list.length){ supResults.innerHTML = '<div class="px-3 py-2 text-gray-400">Tidak ada hasil</div>'; }
        list.forEach(s => {
            const div = document.createElement('div');
            div.className = 'px-3 py-2 hover:bg-blue-50 cursor-pointer';
            div.innerHTML = `<div class="font-medium">${s.name}</div><div class="text-xs text-gray-500">${s.code} · term ${s.payment_term_days} hari</div>`;
            div.addEventListener('click', () => pickSupplier(s));
            supResults.appendChild(div);
        });
        supResults.classList.remove('hidden');
    }
    function pickSupplier(s){
        supId.value = s.id;
        supSearch.value = `${s.name} (${s.code})`;
        supResults.classList.add('hidden');
    }

    // ===== MODAL SUPPLIER =====
    const modal = document.getElementById('modalSupplier');
    const modalError = document.getElementById('modalError');
    function openModal(){
        modalError.classList.add('hidden'); modalError.textContent = '';
        document.getElementById('newSupName').value = supSearch.value || '';
        document.getElementById('newSupPhone').value = '';
        document.getElementById('newSupCity').value = '';
        document.getElementById('newSupTerm').value = 30;
        modal.classList.remove('hidden'); modal.classList.add('flex');
        document.getElementById('newSupName').focus();
    }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }
    document.getElementById('btnNewSupplier').addEventListener('click', openModal);
    document.getElementById('closeModal').addEventListener('click', closeModal);
    document.getElementById('cancelModal').addEventListener('click', closeModal);
    document.getElementById('saveModal').addEventListener('click', async () => {
        const payload = {
            name: document.getElementById('newSupName').value.trim(),
            phone: document.getElementById('newSupPhone').value.trim(),
            city: document.getElementById('newSupCity').value.trim(),
            payment_term_days: parseInt(document.getElementById('newSupTerm').value) || 30,
        };
        if (!payload.name){ modalError.textContent = 'Nama wajib diisi.'; modalError.classList.remove('hidden'); return; }
        try {
            const res = await fetch('/erp/purchasing/api/suppliers', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name=_token]')?.value || '',
                },
                body: JSON.stringify(payload),
            });
            if (!res.ok){
                const err = await res.json().catch(() => ({}));
                modalError.textContent = err.message || 'Gagal simpan.';
                modalError.classList.remove('hidden');
                return;
            }
            const s = await res.json();
            pickSupplier(s);
            closeModal();
        } catch(e){
            modalError.textContent = 'Error jaringan: ' + e.message;
            modalError.classList.remove('hidden');
        }
    });

    // ===== ITEMS =====
    const itemsBody = document.getElementById('itemsBody');
    const expBody = document.getElementById('expensesBody');
    const itemTpl = document.getElementById('itemRowTpl').innerHTML;
    const expTpl = document.getElementById('expenseRowTpl').innerHTML;
    let iCounter = 0, eCounter = 0;

    function addItem(prefill){
        const html = itemTpl.replaceAll('__I__', iCounter++);
        const tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
        const row = tmp.firstElementChild;
        itemsBody.appendChild(row);
        if(prefill){
            row.querySelector('.product-id').value = prefill.product_id ?? '';
            row.querySelector('.sku-in').value = prefill.sku ?? '';
            row.querySelector('.desc-in').value = prefill.description || prefill.asset_name || '';
            row.querySelector('.qty-in').value = prefill.qty ?? 1;
            ensureUnitOption(row.querySelector('.unit-in'), prefill.unit ?? '');
            row.querySelector('.price-in').value = prefill.price ?? 0;
            row.querySelector('.disc-type').value = prefill.discount_type ?? 'nominal';
            row.querySelector('.disc-val').value = prefill.discount_value ?? 0;
            if (prefill.is_asset) {
                const chk = row.querySelector('.is-asset-chk');
                chk.checked = true;
                row.querySelector('.is-asset-hidden').value = '1';
                if (prefill.asset_category_id) {
                    row.querySelector('.asset-category-in').value = prefill.asset_category_id;
                }
                applyAssetMode(row, true);
            }
        }
        bindItem(row);
        recalc();
    }

    function applyAssetMode(row, isAsset) {
        const productCell = row.querySelector('.product-cell');
        const assetCell = row.querySelector('.asset-cell');
        const productIdInput = row.querySelector('.product-id');
        const skuInput = row.querySelector('.sku-in');
        const assetCatSel = row.querySelector('.asset-category-in');
        const hidden = row.querySelector('.is-asset-hidden');
        if (isAsset) {
            productCell.style.display = 'none';
            assetCell.style.display = '';
            productIdInput.value = '';
            skuInput.value = '';
            hidden.value = '1';
            assetCatSel.required = true;
            row.classList.add('bg-orange-50');
        } else {
            productCell.style.display = '';
            assetCell.style.display = 'none';
            hidden.value = '0';
            assetCatSel.value = '';
            assetCatSel.required = false;
            row.classList.remove('bg-orange-50');
        }
    }

    function bindItem(row){
        row.querySelector('.del-item').addEventListener('click', () => { row.remove(); recalc(); });
        row.querySelectorAll('.qty-in,.price-in,.disc-val,.disc-type').forEach(el => el.addEventListener('input', recalc));

        const assetChk = row.querySelector('.is-asset-chk');
        if (assetChk) {
            assetChk.addEventListener('change', () => applyAssetMode(row, assetChk.checked));
        }

        // SKU autocomplete
        const skuIn = row.querySelector('.sku-in');
        const dropdown = row.querySelector('.product-dropdown');
        let timer;
        skuIn.addEventListener('input', () => {
            // user manually typed → reset product_id
            row.querySelector('.product-id').value = '';
            clearTimeout(timer);
            const q = skuIn.value.trim();
            if (!q) { dropdown.classList.remove('show'); return; }
            timer = setTimeout(() => searchProducts(q, dropdown, row), 250);
        });
        skuIn.addEventListener('focus', () => { if (skuIn.value.trim()) searchProducts(skuIn.value.trim(), dropdown, row); });
        document.addEventListener('click', e => {
            if (!dropdown.contains(e.target) && e.target !== skuIn) dropdown.classList.remove('show');
        });

        // Unit dropdown: lazy load product-specific units saat user focus pertama kali.
        // Default options sudah di-render di template (common units), di-replace dengan unit produk setelah load.
        const unitIn = row.querySelector('.unit-in');
        unitIn.addEventListener('focus', () => {
            if (unitIn.dataset.loaded === '1') return;
            const pid = row.querySelector('.product-id').value;
            if (!pid) return;
            unitIn.dataset.loaded = '1';
            loadProductUnits(pid, unitIn, false);
        });
        // Saat user ganti unit, update harga referensi dari cost map.
        unitIn.addEventListener('change', () => applyUnitCostToPrice(row));
    }

    /**
     * Replace options di <select> unit dengan unit-unit yang terdaftar di produk.
     * Sekaligus simpan cost map (harga pembelian per satuan) ke `unitSel._costMap`,
     * supaya nanti pas user ganti unit, harga referensi bisa langsung di-update.
     * Jika `autoSelectBase=true` dan unit-input kosong, set ke base_unit produk
     * dan auto-fill harga referensi.
     */
    async function loadProductUnits(productId, unitSel, autoSelectBase = false){
        try {
            const res = await fetch(`/erp/api/products/${productId}/units`);
            const data = await res.json();
            const opts = [data.base_unit, ...(data.units || []).map(u => u.unit_name)]
                .filter(Boolean)
                .filter((v, i, a) => a.indexOf(v) === i);
            if (!opts.length) return;

            // Simpan cost map: unit_name → cost (dari product_costs).
            // Fallback base_unit pakai product.cost_price kalau tidak ada di product_costs.
            const costMap = {};
            (data.costs || []).forEach(c => { costMap[c.unit_name] = parseFloat(c.cost) || 0; });
            if (data.base_unit && !costMap[data.base_unit]) {
                const fallback = parseFloat(data.cost_price) || parseFloat(data.last_cost) || 0;
                if (fallback > 0) costMap[data.base_unit] = fallback;
            }
            unitSel._costMap = costMap;

            const currentValue = unitSel.value;
            unitSel.innerHTML = '<option value="">—</option>';
            opts.forEach(u => {
                const opt = document.createElement('option');
                opt.value = u;
                opt.textContent = u;
                unitSel.appendChild(opt);
            });
            if (currentValue && [...unitSel.options].some(o => o.value === currentValue)) {
                unitSel.value = currentValue;
            } else if (autoSelectBase && data.base_unit) {
                unitSel.value = data.base_unit;
            }

            if (autoSelectBase) {
                applyUnitCostToPrice(unitSel.closest('tr'));
            }
        } catch(e) { console.error(e); }
    }

    /**
     * Set harga referensi (price-in) berdasarkan unit yang sedang dipilih, pakai cost map.
     * Dipanggil saat pickProduct dan saat user ganti unit.
     */
    function applyUnitCostToPrice(row){
        if (!row) return;
        const unitSel = row.querySelector('.unit-in');
        const priceIn = row.querySelector('.price-in');
        if (!unitSel || !priceIn) return;
        const cost = (unitSel._costMap || {})[unitSel.value];
        if (cost && cost > 0) {
            priceIn.value = cost;
            recalc();
        }
    }

    /**
     * Pastikan saved unit (dari edit / PO autofill) ada sebagai option di select,
     * lalu set sebagai value-nya. Dipanggil dari addItem(prefill).
     */
    function ensureUnitOption(unitSel, savedUnit){
        if (!savedUnit) return;
        if (![...unitSel.options].some(o => o.value === savedUnit)) {
            const opt = document.createElement('option');
            opt.value = savedUnit;
            opt.textContent = savedUnit;
            unitSel.appendChild(opt);
        }
        unitSel.value = savedUnit;
    }

    async function searchProducts(q, dropdown, row){
        try {
            const res = await fetch(`/erp/api/products/search?q=${encodeURIComponent(q)}`);
            const list = await res.json();
            dropdown.innerHTML = '';
            if (!list.length){ dropdown.innerHTML = '<div class="item text-gray-400">Tidak ada hasil. Boleh ketik nama bebas di kolom Nama.</div>'; }
            list.forEach(p => {
                const div = document.createElement('div');
                div.className = 'item';
                div.innerHTML = `<div class="sku">${p.sku || ''}</div><div class="name">${p.name}</div>`;
                div.addEventListener('click', () => pickProduct(p, row, dropdown));
                dropdown.appendChild(div);
            });
            dropdown.classList.add('show');
        } catch(e){ console.error(e); }
    }
    function pickProduct(p, row, dropdown){
        row.querySelector('.product-id').value = p.id;
        row.querySelector('.sku-in').value = p.sku || '';
        const descIn = row.querySelector('.desc-in');
        if (!descIn.value.trim()) descIn.value = p.name;

        // Pre-fill harga dari search response sebagai fallback awal (kalau product_costs kosong).
        const priceIn = row.querySelector('.price-in');
        if (!priceIn.value || parseFloat(priceIn.value) === 0) {
            const refPrice = parseFloat(p.cost_price) || parseFloat(p.last_cost) || parseFloat(p.price) || 0;
            if (refPrice > 0) priceIn.value = refPrice;
        }

        // Eager-load unit options + cost map. autoSelectBase=true akan auto-set unit ke base_unit
        // dan auto-fill harga referensi dari cost map (override fallback di atas kalau ada).
        const unitSel = row.querySelector('.unit-in');
        unitSel.dataset.loaded = '1';
        loadProductUnits(p.id, unitSel, true);

        dropdown.classList.remove('show');
        recalc();
    }

    // ===== EXPENSES =====
    let frequentAccountsCache = null;
    async function loadFrequentAccounts(){
        if (frequentAccountsCache !== null) return frequentAccountsCache;
        try {
            const res = await fetch('/erp/purchasing/api/expense-accounts/frequent');
            frequentAccountsCache = await res.json();
        } catch(e){ frequentAccountsCache = []; }
        return frequentAccountsCache;
    }

    function renderAccountDropdown(list, dropdown, row, opts = {}){
        dropdown.innerHTML = '';
        if (opts.heading){
            const h = document.createElement('div');
            h.className = 'item';
            h.style.cssText = 'background:#f9fafb;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:0.5px;cursor:default;font-weight:600;';
            h.textContent = opts.heading;
            dropdown.appendChild(h);
        }
        if (!list.length){
            const empty = document.createElement('div');
            empty.className = 'item text-gray-400';
            empty.textContent = opts.emptyText || 'Tidak ada hasil.';
            dropdown.appendChild(empty);
        } else {
            list.forEach(a => {
                const div = document.createElement('div');
                div.className = 'item';
                div.innerHTML = `<div class="sku">${a.code || ''}</div><div class="name">${a.name}</div>`;
                div.addEventListener('click', () => pickAccount(a, row, dropdown));
                dropdown.appendChild(div);
            });
        }
        dropdown.classList.add('show');
    }

    function pickAccount(a, row, dropdown){
        row.querySelector('.account-id').value = a.id;
        row.querySelector('.account-search').value = `${a.code} — ${a.name}`;
        dropdown.classList.remove('show');
        recalc();
    }

    async function searchAccounts(q, dropdown, row){
        try {
            const res = await fetch(`/erp/purchasing/api/expense-accounts/search?q=${encodeURIComponent(q)}`);
            const list = await res.json();
            renderAccountDropdown(list, dropdown, row, { emptyText: 'Tidak ada akun cocok.' });
        } catch(e){ console.error(e); }
    }

    function bindAccountSearch(row){
        const input = row.querySelector('.account-search');
        const idIn = row.querySelector('.account-id');
        const dropdown = row.querySelector('.account-dropdown');
        let timer;

        input.addEventListener('input', () => {
            idIn.value = '';
            clearTimeout(timer);
            const q = input.value.trim();
            if (!q){
                loadFrequentAccounts().then(list =>
                    renderAccountDropdown(list, dropdown, row, { heading: 'Sering Dipakai', emptyText: 'Belum ada riwayat.' })
                );
                return;
            }
            timer = setTimeout(() => searchAccounts(q, dropdown, row), 200);
        });
        input.addEventListener('focus', async () => {
            const q = input.value.trim();
            if (!q){
                const list = await loadFrequentAccounts();
                renderAccountDropdown(list, dropdown, row, { heading: 'Sering Dipakai', emptyText: 'Belum ada riwayat. Ketik untuk cari.' });
            } else if (!idIn.value){
                searchAccounts(q, dropdown, row);
            }
        });
        document.addEventListener('click', e => {
            if (!dropdown.contains(e.target) && e.target !== input) dropdown.classList.remove('show');
        });
    }

    function addExpense(prefill){
        const html = expTpl.replaceAll('__E__', eCounter++);
        const tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
        const row = tmp.firstElementChild;
        expBody.appendChild(row);
        bindAccountSearch(row);
        if(prefill){
            row.querySelector('.account-id').value = prefill.account_id;
            if (prefill.account_code || prefill.account_name){
                row.querySelector('.account-search').value = `${prefill.account_code || ''} — ${prefill.account_name || ''}`;
            }
            row.querySelector('input[name$="[description]"]').value = prefill.description ?? '';
            row.querySelector('.mode-in').value = prefill.mode;
            row.querySelector('.amount-in').value = prefill.amount;
        }
        row.querySelector('.del-expense').addEventListener('click', () => { row.remove(); recalc(); });
        row.querySelectorAll('input,select').forEach(el => el.addEventListener('input', recalc));
        recalc();
    }

    // ===== RECALC =====
    function recalc(){
        let sumLine = 0, sumLineDisc = 0;
        document.querySelectorAll('.po-item-row').forEach(r => {
            const qty = parseFloat(r.querySelector('.qty-in').value) || 0;
            const price = cleanNum(r.querySelector('.price-in').value) || 0;
            const dtype = r.querySelector('.disc-type').value;
            const dval = parseFloat(r.querySelector('.disc-val').value) || 0;
            const gross = qty * price;
            const disc = dtype === 'percent' ? (gross * dval / 100) : dval;
            const sub = gross - disc;
            r.querySelector('.line-subtotal').textContent = format(sub);
            sumLine += sub;
            sumLineDisc += disc;
        });

        const gdiscType = document.getElementById('gdiscType').value;
        const gdiscValue = parseFloat(document.getElementById('gdiscValue').value) || 0;
        let gdiscAmount = 0;
        if (gdiscType === 'percent' && gdiscValue > 0) gdiscAmount = sumLine * gdiscValue / 100;
        else if (gdiscType === 'nominal' && gdiscValue > 0) gdiscAmount = gdiscValue;

        let sumExpense = 0;
        document.querySelectorAll('.expense-row').forEach(r => {
            sumExpense += cleanNum(r.querySelector('.amount-in').value) || 0;
        });

        const ppnPct = parseFloat(document.getElementById('ppnPercent').value) || 0;
        const taxBase = sumLine - gdiscAmount + sumExpense;
        const ppn = taxBase * ppnPct / 100;
        const total = taxBase + ppn;

        document.getElementById('sumSubtotal').textContent = format(sumLine);
        document.getElementById('sumLineDisc').textContent = '−' + format(sumLineDisc);
        document.getElementById('sumExpense').textContent = format(sumExpense);
        document.getElementById('sumPpn').textContent = format(ppn);
        document.getElementById('sumTotal').textContent = format(total);
    }

    document.getElementById('addItem').addEventListener('click', () => addItem());
    document.getElementById('addExpense').addEventListener('click', () => addExpense());
    document.getElementById('ppnPercent').addEventListener('input', recalc);
    document.getElementById('gdiscType').addEventListener('change', recalc);
    document.getElementById('gdiscValue').addEventListener('input', recalc);

    @if(isset($po) && $po->items->count())
        @foreach($po->items as $i)
            addItem({
                product_id: @json($i->product_id),
                sku: @json($i->product->sku ?? ''),
                description: @json($i->description ?? ($i->product->name ?? $i->asset_name)),
                qty: {{ $i->qty }},
                unit: @json($i->unit),
                price: {{ $i->price }},
                discount_type: @json($i->discount_type ?? 'nominal'),
                discount_value: {{ $i->discount_value ?? 0 }},
                is_asset: {{ $i->is_asset ? 'true' : 'false' }},
                asset_category_id: {{ $i->asset_category_id ?? 'null' }},
                asset_name: @json($i->asset_name),
            });
        @endforeach
        @if(isset($po) && $po->expenses)
            @foreach($po->expenses as $e)
                addExpense({
                    account_id: {{ $e->account_id }},
                    account_code: @json($e->account->code ?? ''),
                    account_name: @json($e->account->name ?? ''),
                    description: @json($e->description),
                    mode: @json($e->mode),
                    amount: {{ $e->amount }}
                });
            @endforeach
        @endif
    @else
        addItem();
    @endif
})();
</script>
