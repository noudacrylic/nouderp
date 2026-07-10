@csrf

<style>
    .invc-dropdown { position:absolute; left:0; right:0; top:100%; background:#fff; border:1px solid #d1d5db; border-radius:4px; box-shadow:0 4px 12px rgba(0,0,0,0.1); z-index:30; max-height:240px; overflow-y:auto; display:none; }
    .invc-dropdown.show { display:block; }
    .invc-dropdown .item { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6; }
    .invc-dropdown .item:hover { background:#eff6ff; }
    .invc-dropdown .item .sku { font-family: monospace; font-size:11px; color:#2563eb; font-weight:bold; }
    .invc-dropdown .item .name { font-size:13px; color:#1f2937; }
</style>

@php
    $preSupplierId = old('supplier_id', $invoice->supplier_id ?? ($po->supplier_id ?? ''));
    $preSupplier = $preSupplierId ? $suppliers->firstWhere('id', $preSupplierId) : null;
    $prePoId = old('purchase_order_id', $invoice->purchase_order_id ?? ($po->id ?? ''));
    $prePoNumber = $po->po_number ?? ($invoice->purchaseOrder->po_number ?? '');
@endphp

<!-- HEADER (4 kolom × 2 baris) -->
<div class="bg-white rounded shadow p-4 mb-4">
    <h3 class="font-semibold mb-3">Header</h3>
    <div class="grid grid-cols-4 gap-3">
        {{-- Baris 1 --}}
        <div>
            <label class="block text-sm mb-1">Pemasok <span class="text-red-500">*</span></label>
            <div class="flex gap-1">
                <div class="relative flex-1">
                    <input type="text" id="supplierSearch" class="border rounded px-3 py-2 w-full" placeholder="Ketik nama / kode pemasok..." autocomplete="off"
                           value="{{ $preSupplier ? $preSupplier->name . ' (' . $preSupplier->code . ')' : '' }}" required>
                    <input type="hidden" name="supplier_id" id="supplierId" value="{{ $preSupplierId }}">
                    <div id="supplierResults" class="invc-dropdown"></div>
                </div>
                <button type="button" id="btnNewSupplier" class="bg-green-600 text-white px-3 rounded text-sm" title="Tambah pemasok baru">+</button>
            </div>
        </div>
        <div>
            <label class="block text-sm mb-1">Nomor PO</label>
            <div class="relative">
                <input type="text" id="poSearch" class="border rounded px-3 py-2 w-full" placeholder="Pilih pemasok dulu..." autocomplete="off"
                       value="{{ $prePoNumber }}" {{ $preSupplierId ? '' : 'disabled' }}>
                <input type="hidden" name="purchase_order_id" id="poId" value="{{ $prePoId }}">
                <div id="poResults" class="invc-dropdown"></div>
            </div>
        </div>
        <div>
            <label class="block text-sm mb-1">Gudang <span class="text-red-500">*</span></label>
            @php $whSelected = old('warehouse_id', $invoice->warehouse_id ?? ($po->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId())); @endphp
            <select name="warehouse_id" id="warehouseId" class="border rounded px-3 py-2 w-full" required>
                <option value="">— pilih —</option>
                @foreach($warehouses as $w)
                    <option value="{{ $w->id }}" @selected($whSelected == $w->id)>{{ $w->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm mb-1">Tanggal Faktur <span class="text-red-500">*</span></label>
            <input type="date" name="invoice_date" class="border rounded px-3 py-2 w-full" value="{{ old('invoice_date', isset($invoice) ? $invoice->invoice_date->format('Y-m-d') : now()->format('Y-m-d')) }}" required>
        </div>

        {{-- Baris 2 --}}
        <div>
            <label class="block text-sm mb-1">No. Faktur Pemasok</label>
            <input type="text" name="supplier_invoice_no" class="border rounded px-3 py-2 w-full" value="{{ old('supplier_invoice_no', $invoice->supplier_invoice_no ?? '') }}">
        </div>
        <div>
            <label class="block text-sm mb-1">Tgl Faktur Pemasok</label>
            <input type="date" name="supplier_invoice_date" class="border rounded px-3 py-2 w-full" value="{{ old('supplier_invoice_date', isset($invoice) && $invoice->supplier_invoice_date ? $invoice->supplier_invoice_date->format('Y-m-d') : '') }}">
        </div>
        <div>
            <label class="block text-sm mb-1">Jatuh Tempo</label>
            <input type="date" name="due_date" class="border rounded px-3 py-2 w-full" value="{{ old('due_date', isset($invoice) && $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '') }}">
        </div>
        <div>
            <label class="block text-sm mb-1">Saldo DP Pemasok</label>
            <input type="text" id="dpDisplay" class="border rounded px-3 py-2 w-full bg-purple-50 text-purple-700 font-semibold text-right" readonly value="Rp 0">
            <p class="text-[10px] text-gray-500 mt-1">Dipakai manual lewat tombol "Pakai Saldo DP" di faktur setelah diposting.</p>
        </div>
    </div>
</div>

<!-- ITEMS -->
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h3 class="font-semibold">Items</h3>
            <p class="text-xs text-gray-500">Cari SKU/nama untuk pilih produk. Nama bisa diedit untuk catatan tambahan.</p>
        </div>
        <button type="button" id="addItem" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">+ Tambah Item</button>
    </div>
    <table class="w-full text-sm" id="itemsTable">
        <thead class="bg-gray-50 border-b text-gray-600 text-xs uppercase">
            <tr>
                <th class="px-2 py-2 text-center" style="width:60px;" title="Centang untuk mark baris sebagai pembelian aset tetap">Aset?</th>
                <th class="px-2 py-2 text-left" style="width:240px;">SKU / Kategori Aset</th>
                <th class="px-2 py-2 text-left">Nama Produk / Aset</th>
                <th class="px-2 py-2 text-right" style="width:80px;">Qty</th>
                <th class="px-2 py-2 text-left" style="width:80px;">Unit</th>
                <th class="px-2 py-2 text-right" style="width:120px;">Harga</th>
                <th class="px-2 py-2 text-right" style="width:160px;">Diskon Line</th>
                <th class="px-2 py-2 text-right" style="width:120px;">Subtotal</th>
                <th class="px-2 py-2 text-right" style="width:120px;">Cost Akhir</th>
                <th style="width:30px;"></th>
            </tr>
        </thead>
        <tbody id="itemsBody"></tbody>
    </table>
    <p class="text-xs text-gray-500 mt-2"><strong>Aset:</strong> centang untuk mark baris sebagai aset tetap. Saat invoice di-post, master aset (status draft) otomatis dibuat di modul Aset Tetap.</p>
</div>

<!-- EXPENSES -->
<div class="bg-white rounded shadow p-4 mb-4">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h3 class="font-semibold">Biaya Tambahan (Expense)</h3>
            <p class="text-xs text-gray-500">Mode <b>Capitalized</b> = nambah HPP barang. Mode <b>Direct Expense</b> = langsung jadi beban.</p>
        </div>
        <button type="button" id="addExpense" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">+ Tambah Biaya</button>
    </div>
    <table class="w-full text-sm" id="expensesTable">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-2 py-2 text-left">Akun</th>
                <th class="px-2 py-2 text-left">Keterangan</th>
                <th class="px-2 py-2 text-left w-44">Mode</th>
                <th class="px-2 py-2 text-right w-32">Nominal</th>
                <th class="w-10"></th>
            </tr>
        </thead>
        <tbody id="expensesBody"></tbody>
    </table>
</div>

<!-- BOTTOM: CATATAN (kiri) + SUMMARY (kanan) -->
<div class="grid grid-cols-3 gap-4 mb-4">
    <div class="bg-white rounded shadow p-4 col-span-2">
        <h3 class="font-semibold mb-2">Catatan</h3>
        <textarea name="notes" rows="8" class="border rounded px-3 py-2 w-full text-sm">{{ old('notes', $invoice->notes ?? '') }}</textarea>
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
                        // Prefill prio: invoice (edit) → PO (create dari ?po_id=X) → default 'nominal'.
                        $defaultGdiscType = $invoice->global_discount_type
                            ?? ($po->global_discount_type ?? null)
                            ?? 'nominal';
                        $defaultGdiscValue = $invoice->global_discount_value
                            ?? ($po->global_discount_value ?? 0);
                    @endphp
                    <select name="global_discount_type" id="gdiscType"
                        class="border border-gray-300 rounded px-2 py-1 w-20 bg-white">
                        <option value="">—</option>
                        <option value="percent" @selected(old('global_discount_type', $defaultGdiscType) === 'percent')>%</option>
                        <option value="nominal" @selected(old('global_discount_type', $defaultGdiscType) === 'nominal')>Rp</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="global_discount_value" id="gdiscValue"
                        value="{{ old('global_discount_value', $defaultGdiscValue) }}"
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
                    value="{{ old('ppn_percent', $invoice->ppn_percent ?? ($po->ppn_percent ?? 0)) }}"
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
    <a href="{{ route('purchasing.invoices.index') }}" class="px-4 py-2 border rounded text-sm font-bold text-gray-600 hover:bg-gray-50 transition">Batal</a>
    <button type="submit" name="_after_save" value=""
        class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm font-bold transition">
        Draf
    </button>
    <button type="submit" name="_after_save" value="post"
        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold transition">
        Simpan
    </button>
    <button type="submit" name="_after_save" value="print"
        class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-bold transition">
        Cetak
    </button>
</div>

<!-- MODAL: Tambah Supplier Cepat (copy dari PO form) -->
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
    <tr class="border-b item-row">
        <td class="px-2 py-1 text-center">
            <input type="hidden" name="items[__I__][is_asset]" value="0" class="is-asset-hidden">
            <input type="checkbox" class="is-asset-chk" title="Centang jika baris ini adalah pembelian aset tetap">
        </td>
        <td class="px-2 py-1">
            <input type="hidden" name="items[__I__][purchase_order_item_id]" class="po-item-id">
            <div class="relative product-cell">
                <input type="text" class="border rounded px-2 py-1.5 w-full font-mono text-xs sku-in" placeholder="Cari SKU/nama..." autocomplete="off">
                <input type="hidden" name="items[__I__][product_id]" class="product-id">
                <div class="invc-dropdown product-dropdown"></div>
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
        <td class="px-2 py-1"><input type="text" name="items[__I__][description]" class="border rounded px-2 py-1.5 w-full desc-in" placeholder="Nama produk / aset" required></td>
        <td class="px-2 py-1"><input type="number" step="0.01" min="0.01" name="items[__I__][qty]" class="border rounded px-2 py-1.5 w-full text-right qty-in" required></td>
        <td class="px-2 py-1">
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
        <td class="px-2 py-1"><input type="text" inputmode="numeric" name="items[__I__][price]" class="rupiah-input border rounded px-2 py-1.5 w-full text-right price-in" required></td>
        <td class="px-2 py-1">
            <div class="flex gap-1">
                <select name="items[__I__][discount_type]" class="border rounded px-1 py-1.5 text-xs disc-type" style="width:50px;">
                    <option value="percent">%</option>
                    <option value="nominal">Rp</option>
                </select>
                <input type="number" step="0.01" min="0" name="items[__I__][discount_value]" class="border rounded px-2 py-1.5 w-full text-right disc-val" value="0">
            </div>
        </td>
        <td class="px-2 py-1 text-right line-subtotal">0</td>
        <td class="px-2 py-1 text-right text-gray-500 final-cost">0</td>
        <td class="px-2 py-1"><button type="button" class="text-red-600 del-item">×</button></td>
    </tr>
</template>

<template id="expenseRowTpl">
    <tr class="border-b expense-row">
        <td class="px-2 py-1">
            <div class="relative">
                <input type="text" class="border rounded px-2 py-1 w-full account-search" placeholder="Cari kode / nama akun..." autocomplete="off" required>
                <input type="hidden" name="expenses[__E__][account_id]" class="account-id">
                <div class="invc-dropdown account-dropdown"></div>
            </div>
        </td>
        <td class="px-2 py-1"><input type="text" name="expenses[__E__][description]" class="border rounded px-2 py-1 w-full"></td>
        <td class="px-2 py-1">
            <select name="expenses[__E__][mode]" class="border rounded px-2 py-1 w-full mode-in">
                <option value="capitalized">Capitalized (masuk HPP)</option>
                <option value="direct_expense">Direct Expense (beban)</option>
            </select>
        </td>
        <td class="px-2 py-1"><input type="text" inputmode="numeric" name="expenses[__E__][amount]" class="rupiah-input border rounded px-2 py-1 w-full text-right amount-in" required></td>
        <td class="px-2 py-1"><button type="button" class="text-red-600 del-expense">×</button></td>
    </tr>
</template>

<script>
(function(){
    function format(n){ return new Intl.NumberFormat('id-ID',{maximumFractionDigits:2}).format(n); }
    function rp(n){ return 'Rp ' + format(n); }

    // ===== SUPPLIER SEARCH (copy dari PO form) =====
    const supSearch = document.getElementById('supplierSearch');
    const supId = document.getElementById('supplierId');
    const supResults = document.getElementById('supplierResults');
    const poSearch = document.getElementById('poSearch');
    const poId = document.getElementById('poId');
    const poResults = document.getElementById('poResults');
    const dpDisplay = document.getElementById('dpDisplay');
    const warehouseSel = document.getElementById('warehouseId');
    const ppnInput = document.getElementById('ppnPercent');
    let supTimer = null;
    let openPos = [];

    supSearch.addEventListener('input', () => {
        supId.value = '';
        clearSupplierContext();
        clearTimeout(supTimer);
        const q = supSearch.value.trim();
        if (!q) { supResults.classList.remove('show'); return; }
        supTimer = setTimeout(() => doSupSearch(q), 250);
    });
    supSearch.addEventListener('focus', () => { if (supSearch.value.trim() && !supId.value) doSupSearch(supSearch.value.trim()); });
    document.addEventListener('click', e => {
        if (!supResults.contains(e.target) && e.target !== supSearch) supResults.classList.remove('show');
    });
    async function doSupSearch(q){
        try {
            const res = await fetch(`/erp/purchasing/api/suppliers/search?q=${encodeURIComponent(q)}`);
            renderSupResults(await res.json());
        } catch(e){ console.error(e); }
    }
    function renderSupResults(list){
        supResults.innerHTML = '';
        if (!list.length){ supResults.innerHTML = '<div class="item text-gray-400">Tidak ada hasil</div>'; }
        list.forEach(s => {
            const div = document.createElement('div');
            div.className = 'item';
            div.innerHTML = `<div class="font-medium">${s.name}</div><div class="text-xs text-gray-500">${s.code} · term ${s.payment_term_days || 0} hari</div>`;
            div.addEventListener('click', () => pickSupplier(s));
            supResults.appendChild(div);
        });
        supResults.classList.add('show');
    }
    function pickSupplier(s){
        supId.value = s.id;
        supSearch.value = `${s.name} (${s.code})`;
        supResults.classList.remove('show');
        loadSupplierContext(s.id);
    }

    function clearSupplierContext(){
        openPos = [];
        poSearch.value = '';
        poSearch.disabled = true;
        poSearch.placeholder = 'Pilih supplier dulu...';
        poId.value = '';
        dpDisplay.value = 'Rp 0';
    }

    async function loadSupplierContext(supplierId){
        if (!supplierId) { clearSupplierContext(); return; }
        poSearch.disabled = false;
        poSearch.placeholder = 'Ketik / pilih PO...';
        try {
            const [dpRes, poRes] = await Promise.all([
                fetch(`/erp/purchasing/api/suppliers/${supplierId}/open-dp`),
                fetch(`/erp/purchasing/api/suppliers/${supplierId}/open-pos`),
            ]);
            const dpData = await dpRes.json();
            dpDisplay.value = rp(parseFloat(dpData.dp_balance || 0));
            openPos = await poRes.json();
        } catch(e){ console.error(e); }
    }

    // ===== PO LIVESEARCH =====
    poSearch.addEventListener('focus', () => renderPoResults(filterPos(poSearch.value.trim())));
    poSearch.addEventListener('input', () => {
        poId.value = '';
        renderPoResults(filterPos(poSearch.value.trim()));
    });
    document.addEventListener('click', e => {
        if (!poResults.contains(e.target) && e.target !== poSearch) poResults.classList.remove('show');
    });
    function filterPos(q){
        if (!q) return openPos;
        const lq = q.toLowerCase();
        return openPos.filter(p => (p.po_number || '').toLowerCase().includes(lq));
    }
    function renderPoResults(list){
        poResults.innerHTML = '';
        if (!openPos.length){
            poResults.innerHTML = '<div class="item text-gray-400">Tidak ada PO open untuk pemasok ini.</div>';
        } else if (!list.length){
            poResults.innerHTML = '<div class="item text-gray-400">Tidak ada hasil.</div>';
        } else {
            list.forEach(p => {
                const div = document.createElement('div');
                div.className = 'item';
                div.innerHTML = `<div class="sku">${p.po_number}</div><div class="name">${p.po_date} · sisa ${rp(p.remaining_value)}</div>`;
                div.addEventListener('click', () => pickPo(p));
                poResults.appendChild(div);
            });
        }
        poResults.classList.add('show');
    }
    async function pickPo(p){
        poId.value = p.id;
        poSearch.value = p.po_number;
        poResults.classList.remove('show');
        // Auto-fill items + warehouse + ppn + diskon global + expenses dari PO
        try {
            const res = await fetch(`/erp/purchasing/api/po/${p.id}/items`);
            const data = await res.json();
            if (data.po){
                if (data.po.warehouse_id) warehouseSel.value = data.po.warehouse_id;
                if (data.po.ppn_percent != null) ppnInput.value = data.po.ppn_percent;
                // Diskon Global dari PO
                const gdiscType = document.getElementById('gdiscType');
                const gdiscValue = document.getElementById('gdiscValue');
                if (gdiscType && data.po.global_discount_type) gdiscType.value = data.po.global_discount_type;
                if (gdiscValue) gdiscValue.value = data.po.global_discount_value || 0;
            }
            // Reset items table dan isi ulang dari PO
            document.getElementById('itemsBody').innerHTML = '';
            iCounter = 0;
            (data.items || []).forEach(it => {
                const remaining = parseFloat(it.qty_remaining || 0);
                if (remaining <= 0) return; // skip yang sudah full invoiced
                addItem({
                    purchase_order_item_id: it.id,
                    product_id: it.product_id,
                    product_sku: it.product_sku || '',
                    product_name: it.product_name || '',
                    description: it.description || it.product_name || '',
                    qty: remaining,
                    unit: it.unit || '',
                    price: it.price || 0,
                    discount_type: it.discount_type || 'nominal',
                    discount_value: it.discount_value || 0,
                });
            });
            // Reset expenses table dan isi ulang dari PO
            document.getElementById('expensesBody').innerHTML = '';
            eCounter = 0;
            (data.expenses || []).forEach(e => {
                addExpense({
                    account_id: e.account_id,
                    account_code: e.account_code || '',
                    account_name: e.account_name || '',
                    description: e.description || '',
                    mode: e.mode || 'capitalized',
                    amount: e.amount || 0,
                });
            });
            recalc();
        } catch(e){ console.error(e); }
    }

    // ===== MODAL SUPPLIER (copy dari PO form) =====
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

    // ===== ITEMS / EXPENSES (logic asli) =====
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
            row.querySelector('.po-item-id').value = prefill.purchase_order_item_id ?? '';
            row.querySelector('.product-id').value = prefill.product_id ?? '';
            row.querySelector('.sku-in').value = prefill.product_sku ?? '';
            row.querySelector('.desc-in').value = prefill.description || prefill.product_name || prefill.asset_name || '';
            row.querySelector('.qty-in').value = prefill.qty;
            ensureUnitOption(row.querySelector('.unit-in'), prefill.unit ?? '');
            row.querySelector('.price-in').value = prefill.price;
            row.querySelector('.disc-type').value = prefill.discount_type ?? 'nominal';
            // Fallback `||` (bukan `??`) supaya legacy data dengan discount_value=0 tapi discount nominal lama tetap kepanggil.
            row.querySelector('.disc-val').value = prefill.discount_value || prefill.discount || 0;
            // Asset prefill
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
        row.querySelectorAll('.qty-in,.price-in,.disc-val,.disc-type,.unit-in,.desc-in').forEach(el => el.addEventListener('input', recalc));

        // Asset toggle
        const assetChk = row.querySelector('.is-asset-chk');
        if (assetChk) {
            assetChk.addEventListener('change', () => applyAssetMode(row, assetChk.checked));
        }

        // Product SKU / nama livesearch (sama seperti PO)
        const skuIn = row.querySelector('.sku-in');
        const idIn = row.querySelector('.product-id');
        const dropdown = row.querySelector('.product-dropdown');
        let timer;
        skuIn.addEventListener('input', () => {
            idIn.value = '';
            clearTimeout(timer);
            const q = skuIn.value.trim();
            if (!q){ dropdown.classList.remove('show'); return; }
            timer = setTimeout(() => searchProducts(q, dropdown, row), 250);
        });
        skuIn.addEventListener('focus', () => {
            if (skuIn.value.trim()) searchProducts(skuIn.value.trim(), dropdown, row);
        });
        document.addEventListener('click', e => {
            if (!dropdown.contains(e.target) && e.target !== skuIn) dropdown.classList.remove('show');
        });

        // Unit dropdown: lazy load product-specific units saat focus pertama kali.
        // Default options sudah di-render di template (common units), di-replace dengan unit produk setelah load.
        const unitSel = row.querySelector('.unit-in');
        unitSel.addEventListener('focus', () => {
            if (unitSel.dataset.loaded === '1') return;
            const pid = idIn.value;
            if (!pid) return;
            unitSel.dataset.loaded = '1';
            loadProductUnits(pid, unitSel, false);
        });
        // Saat user ganti unit, update harga referensi dari cost map.
        unitSel.addEventListener('change', () => applyUnitCostToPrice(row));
    }

    async function loadProductUnits(productId, unitSel, autoSelectBase = false){
        try {
            const res = await fetch(`/erp/api/products/${productId}/units`);
            const data = await res.json();
            const opts = [data.base_unit, ...(data.units || []).map(u => u.unit_name)]
                .filter(Boolean)
                .filter((v, i, a) => a.indexOf(v) === i);
            if (!opts.length) return;

            // Cost map: unit_name → cost (dari product_costs). Fallback base_unit pakai cost_price/last_cost.
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
            if (!list.length){
                dropdown.innerHTML = '<div class="item text-gray-400">Tidak ada produk cocok.</div>';
            } else {
                list.forEach(p => {
                    const div = document.createElement('div');
                    div.className = 'item';
                    div.innerHTML = `<div class="sku">${p.sku || ''}</div><div class="name">${p.name}</div>`;
                    div.addEventListener('click', () => pickProduct(p, row, dropdown));
                    dropdown.appendChild(div);
                });
            }
            dropdown.classList.add('show');
        } catch(e){ console.error(e); }
    }

    function pickProduct(p, row, dropdown){
        row.querySelector('.product-id').value = p.id;
        row.querySelector('.sku-in').value = p.sku || '';
        const descIn = row.querySelector('.desc-in');
        if (!descIn.value.trim()) descIn.value = p.name;
        const priceIn = row.querySelector('.price-in');
        if (!priceIn.value || parseFloat(priceIn.value) === 0) {
            // Konteks pembelian: prefer cost_price (manual saved), fallback ke last_cost (auto dari invoice), terakhir display_price.
            const refPrice = parseFloat(p.cost_price) || parseFloat(p.last_cost) || parseFloat(p.price) || 0;
            if (refPrice > 0) priceIn.value = refPrice;
        }

        // Eager-load unit options dari produk + auto-select base_unit kalau unit kosong.
        const unitSel = row.querySelector('.unit-in');
        unitSel.dataset.loaded = '1';
        loadProductUnits(p.id, unitSel, true);

        dropdown.classList.remove('show');
        recalc();
    }

    // Akun expense — frequent + livesearch (port dari PO)
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
        row.querySelector('.amount-in').addEventListener('input', recalc);
        row.querySelector('.mode-in').addEventListener('change', recalc);
        recalc();
    }
    function recalc(){
        let sumItem = 0, sumDisc = 0;
        const itemRows = [...document.querySelectorAll('.item-row')];
        const lineSubs = itemRows.map(r => {
            const qty = parseFloat(r.querySelector('.qty-in').value) || 0;
            const price = window.cleanNumber(r.querySelector('.price-in').value) || 0;
            const dtype = r.querySelector('.disc-type').value;
            const dval = parseFloat(r.querySelector('.disc-val').value) || 0;
            const gross = qty * price;
            const disc = dtype === 'percent' ? (gross * dval / 100) : dval;
            const sub = gross - disc;
            r.querySelector('.line-subtotal').textContent = format(sub);
            sumItem += sub;
            sumDisc += disc;
            return { row: r, qty: qty, sub: sub };
        });

        let sumExpCap = 0, sumExpDir = 0;
        document.querySelectorAll('.expense-row').forEach(r => {
            const amt = window.cleanNumber(r.querySelector('.amount-in').value) || 0;
            const mode = r.querySelector('.mode-in').value;
            if (mode === 'capitalized') sumExpCap += amt;
            else sumExpDir += amt;
        });
        const sumExpense = sumExpCap + sumExpDir;

        // Diskon Global (dari panel summary)
        const gdiscType = document.getElementById('gdiscType').value;
        const gdiscValue = parseFloat(document.getElementById('gdiscValue').value) || 0;
        let gdiscAmount = 0;
        if (gdiscType === 'percent' && gdiscValue > 0) gdiscAmount = sumItem * gdiscValue / 100;
        else if (gdiscType === 'nominal' && gdiscValue > 0) gdiscAmount = gdiscValue;

        lineSubs.forEach(o => {
            const share = (sumItem > 0 && sumExpCap > 0) ? (o.sub / sumItem * sumExpCap) : 0;
            // Distribusikan gdisc proporsional ke line — agar Cost Akhir match dengan FIFO cost di backend.
            const gdiscShare = (sumItem > 0 && gdiscAmount > 0) ? (o.sub / sumItem * gdiscAmount) : 0;
            const finalCost = o.qty > 0 ? (o.sub - gdiscShare + share) / o.qty : 0;
            o.row.querySelector('.final-cost').textContent = format(finalCost);
        });

        const ppnPct = parseFloat(ppnInput.value) || 0;
        const taxBase = sumItem - gdiscAmount + sumExpense;
        const ppn = taxBase * ppnPct / 100;
        const total = taxBase + ppn;

        document.getElementById('sumSubtotal').textContent = format(sumItem);
        document.getElementById('sumLineDisc').textContent = '−' + format(sumDisc);
        document.getElementById('sumExpense').textContent = format(sumExpense);
        document.getElementById('sumPpn').textContent = format(ppn);
        document.getElementById('sumTotal').textContent = format(total);
    }

    document.getElementById('addItem').addEventListener('click', () => addItem());
    document.getElementById('addExpense').addEventListener('click', () => addExpense());
    ppnInput.addEventListener('input', recalc);
    document.getElementById('gdiscType').addEventListener('change', recalc);
    document.getElementById('gdiscValue').addEventListener('input', recalc);

    // ===== INIT =====
    @if(isset($invoice) && $invoice->items->count())
        @foreach($invoice->items as $i)
            addItem({
                purchase_order_item_id: @json($i->purchase_order_item_id),
                product_id: {{ $i->product_id ?? 'null' }},
                product_sku: @json($i->product->sku ?? ''),
                product_name: @json($i->product->name ?? ''),
                description: @json($i->description ?? ($i->product->name ?? $i->asset_name)),
                qty: {{ $i->qty }},
                unit: @json($i->unit),
                price: {{ $i->price }},
                discount_type: @json($i->discount_type ?? 'nominal'),
                discount_value: {{ $i->discount_value ?? 0 }},
                discount: {{ $i->discount ?? 0 }},
                is_asset: {{ $i->is_asset ? 'true' : 'false' }},
                asset_category_id: {{ $i->asset_category_id ?? 'null' }},
                asset_name: @json($i->asset_name),
            });
        @endforeach
        @foreach($invoice->expenses as $e)
            addExpense({
                account_id: {{ $e->account_id }},
                account_code: @json($e->account->code ?? ''),
                account_name: @json($e->account->name ?? ''),
                description: @json($e->description),
                mode: @json($e->mode),
                amount: {{ $e->amount }},
            });
        @endforeach
    @elseif($po && $po->items->count())
        @foreach($po->items as $i)
            addItem({
                purchase_order_item_id: {{ $i->id }},
                product_id: {{ $i->product_id ?? 'null' }},
                product_sku: @json($i->product->sku ?? ''),
                product_name: @json($i->product->name ?? ''),
                description: @json($i->description ?? ($i->product->name ?? $i->asset_name)),
                qty: {{ max(0, $i->qty - $i->qty_invoiced) }},
                unit: @json($i->unit),
                price: {{ $i->price }},
                discount_type: @json($i->discount_type ?? 'nominal'),
                discount_value: {{ $i->discount_value ?? 0 }},
                discount: 0,
                is_asset: {{ $i->is_asset ? 'true' : 'false' }},
                asset_category_id: {{ $i->asset_category_id ?? 'null' }},
                asset_name: @json($i->asset_name),
            });
        @endforeach
        @if($po->expenses && $po->expenses->count())
            @foreach($po->expenses as $e)
                addExpense({
                    account_id: {{ $e->account_id }},
                    account_code: @json($e->account->code ?? ''),
                    account_name: @json($e->account->name ?? ''),
                    description: @json($e->description),
                    mode: @json($e->mode),
                    amount: {{ $e->amount }},
                });
            @endforeach
        @endif
    @else
        addItem();
    @endif

    // Saat edit / pre-pick supplier: load DP & open POs
    if (supId.value) loadSupplierContext(supId.value);
})();
</script>
