@props([
    'action' => '',
    'method' => 'POST',
    'type' => 'quotation',
    'customers' => collect(),
    'warehouses' => collect(),
    'products' => collect(),
    'quotations' => collect(),
    'quotation' => null,
    'so' => null,
    'delivery' => null,
    'invoice' => null,
    'items' => null
])

@php
    $invoice = $invoice ?? null;
    $model = $invoice ?? ($quotation ?? ($so ?? null));
    
    // Items handling: prioritize old() for validation recovery, then explicit $items, then model relations, then empty
    if (old('items')) {
        $items = collect();
        foreach (old('items') as $oldItem) {
            $items->push((object)[
                'product_id' => $oldItem['product_id'] ?? null,
                'unit_id' => $oldItem['unit_id'] ?? null,
                'unit_name' => $oldItem['unit_name'] ?? null,
                'qty' => $oldItem['qty'] ?? 1,
                // Parse Indonesian-format ("890.000") via clean_number agar tidak kehilangan 3 nol saat di-render ulang
                'unit_price' => clean_number($oldItem['unit_price'] ?? 0),
                'discount_type' => $oldItem['discount_type'] ?? 'percent',
                'discount_value' => clean_number($oldItem['discount_value'] ?? 0),
                'product' => \App\Core\Inventory\Product::find($oldItem['product_id'] ?? 0)
            ]);
        }
    } else {
        $items = $items ?? ($model?->items ?? collect());
    }
@endphp

<style>
    textarea {
        resize: vertical;
        min-height: 120px;
    }

    .form-control {
        height: 38px;
        font-size: 13px;
        background-color: #fff !important;
        border-color: #e5e7eb !important;
    }

    label {
        font-size: 12px;
        margin-bottom: 4px;
        color: #6b7280;
        font-weight: 500;
    }

    .card, .summary-box {
        background: #fff !important;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
    }
</style>

<form id="transactionForm" method="POST" action="{{ $action }}" enctype="multipart/form-data">
    @csrf

    <input type="hidden" name="quotation_id" id="quotation_id" value="{{ $quotation->id ?? ($so->quotation_id ?? '') }}">

    @if($method == 'PUT')
        @method('PUT')
    @endif

    <div class="space-y-4">

        {{-- ZONE 1: HEADER (Modul-Spesifik) — Tidak tampil di Invoice & Quotation karena mereka punya header sendiri --}}
        @if($type !== 'invoice' && $type !== 'quotation')
        <div class="card p-4 shadow-sm border border-gray-100">
            <x-transaction-header-fields
                :customers="$customers"
                :warehouses="$warehouses"
                :quotations="$quotations"
                :quotation="$quotation"
                :so="$so"
                :invoice="$invoice"
                :type="$type"
            />
        </div>
        @endif

        {{-- ZONE 2: BODY (UNIVERSAL) --}}
        
        {{-- 2A. ITEMS TABLE (Harus Selalu Sama) --}}
        <div class="card shadow-sm border border-gray-100 overflow-visible">
            <x-transaction-items
                :items="$items"
                :products="$products"
                :type="$type"
                :so="$so"
                :invoice="$invoice"
            />
        </div>

        {{-- 2B. BOTTOM AREA --}}
        @php $hasShip = in_array($type, ['sales_order', 'invoice'], true); @endphp
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-stretch">
            {{-- CATATAN --}}
            <div class="{{ $hasShip ? 'md:col-span-3' : 'md:col-span-8' }}">
                <div class="card p-4 shadow-sm border border-gray-100 h-full">
                    @if($type === 'quotation')
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <div>
                                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Perihal</label>
                                <input type="text" name="perihal" value="{{ old('perihal', $model->perihal ?? 'Surat Penawaran') }}"
                                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Mis. Surat Penawaran">
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Lampiran</label>
                                <input type="text" name="lampiran" value="{{ old('lampiran', $model->lampiran ?? '') }}"
                                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm" placeholder="Mis. Gambar Produk (opsional)">
                            </div>
                        </div>
                    @endif
                    <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-2">Catatan Transaksi</label>
                    <textarea
                        name="notes"
                        class="form-control w-full border border-gray-200 rounded-lg p-3 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition bg-white"
                        placeholder="Tambahkan informasi / instruksi khusus..."
                        rows="{{ $hasShip ? 5 : 3 }}"
                        style="min-height: {{ $hasShip ? '120px' : '80px' }}; background-color: #fff !important;"
                    >{{ old('notes', $model->notes ?? '') }}</textarea>
                </div>
            </div>

            {{-- PENGIRIMAN & ONGKIR (hanya SO + Invoice) --}}
            @if($hasShip)
            <div class="md:col-span-5">
                @include('erp._partials.shipping-embed', ['model' => $model ?? null])
            </div>
            @endif

            {{-- RINGKASAN --}}
            <div class="md:col-span-4">
                <div class="card p-4 shadow-sm border border-gray-100 bg-white h-full">
                    <x-transaction-summary
                        :subtotal="$model->subtotal ?? 0"
                        :globalDiscountValue="$model->global_discount_value ?? 0"
                        :globalDiscountType="$model->global_discount_type ?? 'nominal'"
                        :ppnPercent="$model->ppn_percent ?? 0"
                        :shippingCost="$model->shipping_cost ?? $model->shipping_charge ?? 0"
                        :additionalFee="$model->additional_fee ?? $model->selling_expense ?? $model->expense ?? 0"
                    />
                </div>
            </div>
        </div>

        {{-- QUOTATION EXTRAS: Opening text, Payment terms, & Lampiran Gambar --}}
        @if($type === 'quotation')
            @include('erp.sales.quotations._quotation-extras', [
                'quotation' => $quotation ?? null,
                'profile'   => $profile ?? \App\Models\BusinessProfile::instance(),
            ])
        @endif

        {{-- ACTION BUTTONS --}}
        @php
            $cancelRoute = match($type) {
                'quotation'   => route('sales.quotations.index'),
                'sales_order' => route('sales.orders.index'),
                'invoice'     => route('sales.invoices.index'),
                default       => url('/'),
            };
            $isQuotation = $type === 'quotation';
        @endphp
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ $cancelRoute }}"
                class="px-6 py-2.5 rounded-lg font-bold text-gray-500 hover:bg-gray-100 transition">
                Batal
            </a>
            @unless($isQuotation)
                <button type="submit" name="_after_save" value=""
                    class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2.5 rounded-lg font-bold shadow-sm transition">
                    Draf
                </button>
            @endunless
            <button type="submit" name="_after_save" value="{{ $isQuotation ? '' : 'post' }}"
                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg font-bold shadow-md hover:shadow-lg transition">
                Simpan
            </button>
            <button type="submit" name="_after_save" value="print"
                class="bg-green-600 hover:bg-green-700 text-white px-6 py-2.5 rounded-lg font-bold shadow-md hover:shadow-lg transition">
                Cetak
            </button>
        </div>
    </div>
</form>

{{-- QUICK CREATE CUSTOMER (Floating Form) --}}
<div id="quickCustomerForm" class="quick-form fixed hidden z-[9999]">
    <div class="card p-4 shadow-xl border border-gray-200 bg-white w-[320px] rounded-xl">
        <h6 class="text-sm font-bold text-gray-800 mb-3 flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
            </svg>
            Tambah Pelanggan Cepat
        </h6>
        
        <form id="customerForm" action="/customers/store" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1.5">Nama Lengkap <span class="text-red-500">*</span></label>
                <input type="text" name="name" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition" placeholder="Masukkan nama pelanggan..." required>
                <p class="text-[10px] text-gray-400 mt-1.5">No. HP & alamat diisi nanti di kartu Pengiriman.</p>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeQuickCustomer()" class="px-3 py-1.5 text-xs font-bold text-gray-500 hover:bg-gray-100 rounded-lg transition">
                    Batal
                </button>
                <button type="submit" class="bg-blue-600 text-white px-4 py-1.5 text-xs font-bold rounded-lg shadow hover:bg-blue-700 transition">
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<style>

.product-dropdown, .customer-dropdown{
    position:absolute;
    top:100%;
    left:0;
    width:auto;
    min-width: 100%;
    max-width: 600px;
    background:white;
    border:1px solid #cbd5e1;
    z-index: 999999 !important;
    max-height:300px;
    overflow-y:auto;
    box-shadow: 0 15px 30px -5px rgba(0, 0, 0, 0.2);
    border-radius: 0 0 8px 8px;
    margin-top: 1px;
    display: none;
}

.quick-form {
    filter: drop-shadow(0 20px 25px rgb(0 0 0 / 0.15));
}

.quick-form:before {
    content: '';
    position: absolute;
    top: -6px;
    left: 20px;
    width: 12px;
    height: 12px;
    background: white;
    transform: rotate(45deg);
    border-left: 1px solid #e5e7eb;
    border-top: 1px solid #e5e7eb;
}

.product-dropdown:not(:empty),
.customer-dropdown:not(:empty){
    display: block;
}

#items td {
    overflow: visible !important;
}


.customer-dropdown{
    border-top:0;
}

.product-item,
.customer-item{
    padding:10px 14px;
    cursor:pointer;
    border-bottom:1px solid #f1f5f9;
    transition:all .2s;
}

.product-item:hover,
.customer-item:hover{
    background:#f8fafc;
    color: #2563eb;
}

.customer-select{
    position:relative;
    width:100%;
}

.customer-input-group{
    display:flex;
}

.customer-input-group input{
    border-radius:6px 0 0 6px !important;
}

.customer-input-group button{
    border-radius:0 6px 6px 0 !important;
}

.bundle-component{
    background:#f8fafc;
    font-size:12px;
}

.bundle-component td{
    color:#64748b;
    border-bottom: 1px dashed #f1f5f9;
}

.bundle-component td:first-child{
    padding-left:45px !important;
}

.bundle-row{
    background:#fff !important;
}

.bundle-toggle{
    cursor:pointer;
    user-select:none;
    margin-right:8px;
    font-family: monospace;
    display:inline-block;
    width:15px;
    transition: transform 0.2s;
}

.bundle-toggle.expanded{
    transform: rotate(90deg);
}


</style>

<script>
    let state = {
        items: [],
        summary: {},
        extra: {}
    }

    function resetState() {
        state = {
            items: [],
            summary: {},
            extra: {
                discount: 0,
                shipping: 0,
                tax: 0,
                additional_fee: 0
            }
        }
    }

    function cleanNumber(val) {
        if (!val) return 0;
        let s = val.toString().trim();
        
        // Standard Indonesian ERP logic:
        // 1. Remove all dots (thousands separator)
        // 2. Replace comma with dot (decimal separator)
        s = s.replace(/\./g, '').replace(',', '.');
        
        // Final cleaning: keep only digits, decimal dot, and sign
        s = s.replace(/[^0-9.-]/g, '');
        return parseFloat(s) || 0;
    }

    function formatRupiah(number) {
        if (isNaN(number) || number === null) return "Rp 0";
        return "Rp " + Math.round(number).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    function loadSummaryFromDOM() {
        return {
            discount: cleanNumber(document.querySelector('.input-discount')?.value),
            shipping: cleanNumber(document.querySelector('.input-shipping')?.value),
            tax: cleanNumber(document.querySelector('.input-tax')?.value),
            additional_fee: cleanNumber(document.querySelector('.input-additional-fee')?.value),
            discount_type: document.getElementById('globalDiscountType')?.value || 'nominal'
        }
    }

    function bindSummaryInputs() {
        document.querySelectorAll('.input-discount, .input-shipping, .input-tax, .input-additional-fee')
            .forEach(el => {
                el.addEventListener('input', () => {
                    // Update data-raw directly from the input
                    el.dataset.raw = cleanNumber(el.value);
                    recalculate();
                })
            })
            
        document.getElementById('globalDiscountType')?.addEventListener('change', () => {
             recalculate();
        })
    }

    function calculate(items, extra) {
        let subtotal = 0;
        items.forEach(item => {
            // Line total is already net of item discount in recalculate
            const line = (item.qty * item.price) - (item.discount || 0);
            subtotal += line;
        });

        const discount = extra.discount || 0;
        const shipping = extra.shipping || 0;
        const taxPercent = extra.tax || 0;
        const additional_fee = extra.additional_fee || 0;

        // DPP calculation
        const discAmount = (extra.discount_type === 'percent' ? (subtotal * discount / 100) : discount);
        const dpp = subtotal - discAmount;
        
        // Tax calculation based on DPP
        const taxAmount = dpp * (taxPercent / 100);

        // Grand Total Calculation (reset from zero)
        const grand_total = dpp + taxAmount + shipping + additional_fee;

        console.log("CALCULATION DEBUG:", {
            subtotal,
            discount: discAmount,
            dpp,
            tax: taxAmount,
            shipping,
            additional_fee,
            grand_total
        });

        return {
            subtotal,
            grand_total
        };
    }

    function recalculate() {
        // Clear state before recalculating from DOM to ensure no leftover values
        state.items = [];
        
        document.querySelectorAll('#items tr.item-row').forEach(row => {
            let qty = parseFloat(row.querySelector('.qty')?.value) || 0;
            let price = cleanNumber(row.querySelector('.price')?.value) || 0;
            let discVal = cleanNumber(row.querySelector('.discount-value')?.value) || 0;
            let discType = row.querySelector('.discount-type')?.value;
            
            let itemDiscount = (discType === 'percent') ? (qty * price * discVal / 100) : discVal;
            let itemSubtotal = (qty * price) - itemDiscount;
            
            let itemSubtotalEl = row.querySelector('.itemSubtotal');
            if (itemSubtotalEl) {
                itemSubtotalEl.innerText = formatIDR(itemSubtotal);
            }

            state.items.push({
                qty: qty,
                price: price,
                discount: itemDiscount
            });
        });

        state.extra = loadSummaryFromDOM();
        const result = calculate(state.items, state.extra);
        state.summary = result;
        renderSummary(result);
        
        // Update hidden inputs for form submission
        if (document.getElementById('subtotal_input')) document.getElementById('subtotal_input').value = result.subtotal;
        if (document.getElementById('grand_total_input')) document.getElementById('grand_total_input').value = result.grand_total;
    }

    function renderSummary(summary) {
        const subtotalEl = document.querySelector('.summary-subtotal');
        const grandtotalEl = document.querySelector('.summary-grandtotal');
        if (subtotalEl) subtotalEl.innerText = formatIDR(summary.subtotal);
        if (grandtotalEl) grandtotalEl.innerText = formatIDR(summary.grand_total);
    }

    document.addEventListener("DOMContentLoaded", function () {
        const autoToggle = document.getElementById("autoNumber");
        const numberInput = document.getElementById("quotationNumber");

        if (autoToggle && numberInput) {
            autoToggle.addEventListener("change", function () {
                if (this.checked) {
                    numberInput.disabled = true;
                    numberInput.value = "";
                } else {
                    numberInput.disabled = false;
                    numberInput.focus();
                }
            });
        }

        // realtime calculation
        document.addEventListener('input', function (e) {
            if (e.target.closest('#items') || e.target.closest('.summary-section')) {
                recalculate();
            }
        });

        // Customer Search Logic
        $(document).on('keyup', '#customer_search', function(){
            let input = $(this);
            let keyword = input.val();

            if(keyword.length < 2){
                input.siblings('.customer-dropdown').html('');
                return;
            }

            $.get('/erp/api/customers/search', {q: keyword}, function(customers){
                let html = '';
                customers.forEach(function(c){
                    html += `
                    <div class="customer-item" data-id="${c.id}" data-name="${c.name}">
                        <b>${c.code || ''}</b> — ${c.name}
                    </div>
                    `;
                });
                input.closest('.customer-select').find('.customer-dropdown').html(html);
            });
        });

        $(document).on('click', '.customer-item', function(){
            let item = $(this);
            let id = item.data('id');
            let name = item.data('name');

            $('#customer_id').val(id);
            $('#customer_search').val(name);
            $('.customer-dropdown').html('');
            if (window.reloadShippingAddress) window.reloadShippingAddress();

            // Trigger address fetch
            fetch('/erp/customers/address/' + id)
                .then(res => res.json())
                .then(data => {
                    let addrField = document.getElementById('shipping_address');
                    if (addrField) {
                        addrField.value = data.shipping_address ?? '';
                    }
                });
        });

        // Product SKU Search Logic
        $(document).on('input', '.sku-search', function(){
            let input = $(this);
            let keyword = input.val();
            let dropdown = input.closest('td').find('.product-dropdown');

            if(keyword.length < 1){
                dropdown.html('').hide();
                return;
            }

            $.get('/erp/api/products/search', { q: keyword, sellable_only: 1 }, function(products){
                let html = '';
                if(products.length === 0){
                    html = '<div class="p-3 text-xs text-gray-500 italic">Produk tidak ditemukan</div>';
                } else {
                        products.forEach(function(p){
                            html += `
                            <div class="product-item" 
                                data-id="${p.id}" 
                                data-sku="${p.sku}" 
                                data-name="${p.name}"
                                data-price="${p.price}">
                                <b class="text-blue-600">${p.sku}</b> — ${p.name}
                            </div>
                            `;
                        });
                }
                dropdown.html(html).show();
            });

        });

        // Click Product
        $(document).on('click', '.product-item', function(){
            let item = $(this);
            let row = item.closest('tr');
            let productId = item.data('id');

            row.find('.sku-search').val(item.data('sku'));
            row.find('.product_id').val(productId);
            row.find('.description').val(item.data('name'));
            row.find('.price').val(formatIDR(item.data('price') || 0));
            
            // 1. Fetch Stock
            $.get('/erp/api/products/' + productId + '/stock', function(res){
                let stock = res.stock ?? 0;
                let stockDisplay = row.find('.stock');
                stockDisplay.text(stock);
                stockDisplay.css('color', stock <= 0 ? '#dc2626' : '#16a34a').css('font-weight', 'bold');
            });

            // 2. Fetch Units & Prices
            loadUnits(productId, row);

            // 3. Handle Bundle Components
            row.nextUntil('.item-row').remove();
            row.removeClass('bundle-row');
            row.find('.bundle-toggle').remove();
            
            $.get('/erp/api/bundle/'+productId+'/components', function(res){
                if (res.length > 0) {
                    row.addClass('bundle-row');
                    let skuCell = row.find('td:first-child');
                    if(!skuCell.find('.bundle-toggle').length){
                        skuCell.prepend('<span class="bundle-toggle expanded">▾</span>');
                    }
                    generateBundleRows(row, res);

                    // Hitung stok bundle parent = MIN(floor(componentStock / required)) untuk setiap komponen
                    let maxBundle = Infinity;
                    res.forEach(function(c){
                        const required = parseFloat(c.required) || 0;
                        const compStock = parseFloat(c.stock) || 0;
                        if (required > 0) {
                            maxBundle = Math.min(maxBundle, Math.floor(compStock / required));
                        }
                    });
                    if (!isFinite(maxBundle)) maxBundle = 0;

                    let stockDisplay = row.find('.stock');
                    stockDisplay.text(maxBundle);
                    stockDisplay.css('color', maxBundle <= 0 ? '#dc2626' : '#16a34a').css('font-weight', 'bold');
                }
            });

            // Unlock fields
            row.find('.description, .price, .qty, .discount-value, .discount-type, .unit-select').prop('disabled', false).removeClass('bg-gray-50');
            item.parent().html('').hide();
            recalculate();
            if (window._promoOnPick) window._promoOnPick(row, productId);
        });

        function generateBundleRows(row, components) {
            let componentsHtml = '';
            let qty = parseFloat(row.find('.qty').val()) || 1;
            components.forEach(function(c){
                componentsHtml += `
                <tr class="bundle-component" data-required="${c.required}">
                    <td class="p-2 py-1 text-muted font-mono" style="padding-left: 45px !important;">└─ ${c.sku}</td>
                    <td class="p-2 py-1 text-muted italic">${c.name}</td>
                    <td class="component-stock text-center p-2 py-1 text-muted bg-gray-50/50">${c.stock}</td>
                    <td class="p-2 py-1 bg-gray-50/50"></td>
                    <td class="component-qty text-center p-2 py-1 text-muted font-bold bg-gray-50/50">${c.required * qty}</td>
                    <td colspan="4" class="bg-gray-50/50"></td>
                </tr>
                `;
            });
            row.after(componentsHtml);
        }


        function loadUnits(productId, row, selectedUnit = null, forcedPrice = null){
            $.get('/erp/api/products/' + productId + '/units', function(res){
                let unitSelect = row.find('.unit-select');
                unitSelect.empty();
                
                res.units.forEach(u => {
                    let priceData = res.prices.find(p => p.unit_name === u.unit_name);
                    let price = priceData ? priceData.price : 0;
                    unitSelect.append(`<option value="${u.id}" data-price="${price}" data-unit-name="${u.unit_name}">${u.unit_name}</option>`);
                });

                // Set Default Unit & Price
                let targetUnitId = selectedUnit || res.units.find(u => u.unit_name === res.base_unit)?.id;
                if(targetUnitId){
                    unitSelect.val(targetUnitId);
                    
                    // IF FORCED PRICE IS PROVIDED, USE IT. OTHERWISE USE STORE PRICE.
                    if (forcedPrice !== null) {
                        row.find('.price').val(formatIDR(forcedPrice));
                    } else {
                        let selectedOption = unitSelect.find(':selected');
                        let unitName = selectedOption.data('unit-name');
                        let priceData = res.prices.find(p => p.unit_name === unitName);
                        if(priceData) row.find('.price').val(formatIDR(priceData.price));
                    }
                }

                recalculate();
            });
        }

        $(document).on('change', '.unit-select', function(){
            let opt = $(this).find(':selected');
            let price = opt.data('price') || 0;
            let row = $(this).closest('tr');
            row.find('.price').val(formatIDR(price));
            recalculate();
        });

        // Toggle Bundle Components
        $(document).on('click', '.bundle-toggle', function(){
            let toggle = $(this);
            let row = toggle.closest('tr');
            toggle.toggleClass('expanded');
            if(toggle.hasClass('expanded')){
                toggle.text('▾');
                row.nextUntil('.item-row').show();
            } else {
                toggle.text('▸');
                row.nextUntil('.item-row').hide();
            }
        });

        function loadBundleComponents(row, productId){
            $.get('/erp/api/bundle/'+productId+'/components', function(res){
                if (res.length === 0) return;

                generateBundleRows(row, res);

                // Hitung stok bundle parent = MIN(floor(componentStock / required)) per komponen.
                // Bundle parent sendiri tidak punya stok fisik di product_stocks; stok-nya dihitung
                // dari ketersediaan tiap komponen.
                let maxBundle = Infinity;
                res.forEach(function(c){
                    const required = parseFloat(c.required) || 0;
                    const compStock = parseFloat(c.stock) || 0;
                    if (required > 0) {
                        maxBundle = Math.min(maxBundle, Math.floor(compStock / required));
                    }
                });
                if (!isFinite(maxBundle)) maxBundle = 0;

                let stockDisplay = row.find('.stock');
                stockDisplay.text(maxBundle);
                stockDisplay.css('color', maxBundle <= 0 ? '#dc2626' : '#16a34a')
                            .css('font-weight', 'bold');
            });
        }


        // Qty Update (Inc Bundle Logic)
        $(document).on('input', '.qty', function(){
            let qty = parseFloat($(this).val()) || 0;
            let row = $(this).closest('tr');

            row.nextUntil('.item-row').each(function(){
                let required = parseFloat($(this).data('required')) || 0;
                let componentQty = qty * required;
                $(this).find('.component-qty').text(componentQty);

                let stock = parseFloat($(this).find('.component-stock').text());
                if(componentQty > stock){
                    $(this).css('color','#dc2626');
                }else{
                    $(this).css('color','#64748b');
                }
            });
            recalculate();
        });

        // Close dropdown when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.position-relative').length && !$(e.target).closest('.customer-select').length) {
                $('.product-dropdown, .customer-dropdown').html('');
            }
        });

        document.querySelector('[name="warehouse_id"]')?.addEventListener('change', function () {
            document.querySelectorAll('#items tr').forEach(row => {
                let productId = row.querySelector('.product_id')?.value;
                if (productId) updateStockInfo(row, productId);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === "Enter") {
                let el = document.activeElement;
                if (el && el.closest("#items")) {
                    e.preventDefault();
                    addItem();
                }
            }
        });

        $(document).on('change', '#quotation_select', function () {
            let id = $(this).val();
            if (!id) return;
            loadQuotation(id);
        });

    function loadQuotation(id) {
        fetch('/erp/api/quotation/' + id)
            .then(res => res.json())
            .then(data => {
                // Populate Main Fields
                if (document.getElementById('quotation_id')) {
                    document.getElementById('quotation_id').value = data.id;
                }

                // Customer Search Logic
                if (document.getElementById('customer_id')) {
                    document.getElementById('customer_id').value = data.customer_id;
                }
                if (document.getElementById('customer_search')) {
                    document.getElementById('customer_search').value = data.customer ? data.customer.name : '';
                }

                // Warehouse
                let warehouseSelect = document.querySelector('[name="warehouse_id"]');
                if (warehouseSelect) warehouseSelect.value = data.warehouse_id;

                // Notes
                let notesTextarea = document.querySelector('[name="notes"]');
                if (notesTextarea) notesTextarea.value = data.notes ?? '';

                // Metode pengiriman
                let dmSel = document.getElementById('delivery_method');
                if (dmSel) { dmSel.value = data.delivery_method ?? 'kurir'; dmSel.dispatchEvent(new Event('change')); }
                if (window.reloadShippingAddress) window.reloadShippingAddress();

                // Financials
                if (window.setShippingDetail) {
                    window.setShippingDetail({ gross: data.shipping_charge ?? 0, discount_type: 'nominal', discount_value: 0 });
                } else if (document.getElementById('shipping')) {
                    document.getElementById('shipping').value = formatIDR(data.shipping_charge ?? 0);
                }
                if (document.getElementById('globalDiscount')) document.getElementById('globalDiscount').value = formatIDR(data.global_discount_value ?? 0);
                if (document.getElementById('globalDiscountType')) document.getElementById('globalDiscountType').value = data.global_discount_type ?? 'nominal';
                if (document.getElementById('ppn')) document.getElementById('ppn').value = data.ppn_percent ?? 0;
                if (document.getElementById('additional_fee')) document.getElementById('additional_fee').value = formatIDR(data.additional_fee ?? data.expense ?? data.other_expense ?? 0);

                loadQuotationItems(data.items);
            });
    }

    document.querySelectorAll('#items .item-row').forEach(row => {
            let productId = row.querySelector('.product_id')?.value;
            let type = row.dataset.type;
            let unit = row.querySelector('.unit-select')?.value;
            let currentPrice = parseIDR(row.querySelector('.price')?.value);

            if (productId) {
                loadUnits(productId, $(row), unit, currentPrice);

                if(type === 'bundle'){
                    // Bundle: stok parent dihitung dari komponen di loadBundleComponents.
                    // JANGAN panggil updateStockInfo() karena product_stocks bundle parent = 0,
                    // dan respons async-nya bisa menimpa nilai bundle yang sudah benar.
                    row.classList.add('bundle-row');
                    let skuCell = $(row).find('td:first-child');
                    if(!skuCell.find('.bundle-toggle').length){
                        skuCell.prepend('<span class="bundle-toggle expanded">▾</span>');
                    }
                    loadBundleComponents($(row), productId);
                } else {
                    updateStockInfo(row, productId);
                }
            }
        });

        recalculate();

    function resetRow(row) {
        if (!row) return;
        let fields = row.querySelectorAll('input:not(.product_id), select:not(.product-select)');
        fields.forEach(f => {
            f.value = f.classList.contains('qty') ? 1 : (f.classList.contains('discount-value') ? 0 : "");
            f.disabled = true;
            f.classList.add('bg-gray-50');
        });
        row.querySelector('.description').placeholder = "Pilih produk dulu...";
        row.querySelector('.stock-info').innerText = "-";
        row.querySelector('.itemSubtotal').innerText = "0";
    }

    function loadQuotationItems(items) {
        const tbody = document.getElementById('items');
        tbody.innerHTML = '';
        
        items.forEach((item, index) => {
            let p = item.product || {};
            let rowHtml = `
                <tr class="border-t item-row">
                <td class="p-2">
                    <input type="text" class="sku w-full border border-gray-300 rounded px-3 py-2 bg-gray-50 font-mono text-xs" readonly value="${p.sku || ''}">
                    <input type="hidden" name="items[${index}][product_id]" class="product_id" value="${item.product_id}">
                </td>
                <td class="p-2">
                    <input type="text" name="items[${index}][description]" class="description w-full border border-gray-300 rounded px-3 py-2" value="${item.description || (p.name || '')}">
                </td>
                <td class="p-2 text-center stock">
                    <span class="stock-info font-bold text-gray-600">-</span>
                </td>
                <td class="p-2">
                    <select name="items[${index}][unit_id]" class="unit-select w-full border border-gray-300 rounded px-2 py-2 text-xs">
                        <option value="${item.unit_id || ''}">${item.unit_name || '-'}</option>
                    </select>
                </td>
                <td class="p-2">
                    <input type="number" name="items[${index}][qty]" value="${item.qty}" class="qty w-full border border-gray-300 rounded px-3 py-2 text-right">
                </td>
                <td class="p-2">
                    <input type="text" inputmode="numeric" name="items[${index}][unit_price]" value="${formatIDR(item.unit_price)}" class="price rupiah-input w-full border border-gray-300 rounded px-3 py-2 text-right">
                </td>
                <td class="p-2">
                    <div class="flex gap-2">
                        <select name="items[${index}][discount_type]" class="discount-type border border-gray-300 rounded px-2 py-2 w-16 bg-gray-50 text-xs">
                            <option value="percent" ${item.discount_type !== 'nominal' ? 'selected' : ''}>%</option>
                            <option value="nominal" ${item.discount_type === 'nominal' ? 'selected' : ''}>Rp</option>
                        </select>
                        <input type="text" name="items[${index}][discount_value]" value="${formatIDR(item.discount_value)}" class="discount-value border border-gray-300 rounded px-3 py-2 w-full text-right text-xs">
                    </div>
                </td>
                <td class="p-2 text-right font-medium itemSubtotal text-gray-900">0</td>
                <td class="p-2 text-center">
                    <button type="button" onclick="this.closest('tr').remove(); recalculate();" class="text-red-500 hover:text-red-700">✕</button>
                </td>
            </tr>
            `;
            tbody.insertAdjacentHTML('beforeend', rowHtml);
            let newRow = tbody.lastElementChild;
            updateStockInfo(newRow, item.product_id);
            loadUnits(item.product_id, $(newRow), item.unit_id, item.unit_price);
            
            if(p.sale_type === 'bundle'){
                newRow.classList.add('bundle-row');
                let skuCell = $(newRow).find('td:first-child');
                if(!skuCell.find('.bundle-toggle').length){
                    skuCell.prepend('<span class="bundle-toggle expanded">▾</span>');
                }
                loadBundleComponents($(newRow), item.product_id);
            }
        });
        recalculate();
    }
    }); // end DOMContentLoaded

    function updateStockInfo(row, productId) {
        if (!productId) return;

        let display = row.querySelector('.stock') || row.querySelector('.stock-info');
        if (!display) return;

        const warehouseId  = document.getElementById('warehouse_id_hidden')?.value
                          || document.querySelector('[name="warehouse_id"]')?.value
                          || '';
        const salesOrderId = document.getElementById('sales_order_id_hidden')?.value
                          || document.querySelector('[name="sales_order_id"]')?.value
                          || '';
        const params = new URLSearchParams();
        if (warehouseId)  params.set('warehouse_id', warehouseId);
        if (salesOrderId) params.set('sales_order_id', salesOrderId);
        const qs = params.toString();

        fetch(`/erp/api/product-stock/${productId}${qs ? '?' + qs : ''}`)
            .then(res => res.json())
            .then(data => {
                let available = data.stock ?? 0;
                display.innerText = available;
                display.style.color = available <= 0 ? '#dc2626' : '#16a34a';
                display.style.fontWeight = 'bold';
                if (data.qty_on_hand !== undefined && Number(data.qty_reserved_others) > 0) {
                    display.title = `Fisik: ${data.qty_on_hand} · Reservasi SO lain: ${data.qty_reserved_others}`;
                } else if (data.qty_on_hand !== undefined) {
                    display.title = `Fisik: ${data.qty_on_hand}`;
                }
            });
    }

    function addItem() {
        const row = addItemRow();
        if (!row) return;

        // Focus the first input (SKU)
        const skuInput = row.querySelector('.sku-search');
        if (skuInput) {
            skuInput.disabled = false;
            skuInput.classList.remove('bg-gray-50');
            skuInput.focus();
        }
        
        recalculate();
    }

    function formatIDR(number) {
        if (isNaN(number) || number === null) return "0";
        return Math.round(number).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    function parseIDR(string) {
        if (!string) return 0;
        return cleanNumber(string);
    }

    // Auto Format Input
    // NOTE: .price kini pakai formatter global `rupiah-input` (layout) → tidak di-bind di sini
    //       agar tidak terjadi double-format / lompat kursor. Read tetap via cleanNumber di recalculate().
    $(document).on('input keyup', '.discount-value, #shipping, #globalDiscount, #additional_fee', function(){
        let val = $(this).val();
        let clean = cleanNumber(val);
        $(this).val(formatIDR(clean));
        recalculate();
    });

    // Replacement for old calculate function is handled by recalculate

    // Trigger calculation on load
    document.addEventListener("DOMContentLoaded", function() {
        resetState();
        bindSummaryInputs();
        recalculate();
    });

    // Proteksi submit: cegah POST jika data wajib belum lengkap, agar harga di kolom Price
    // tidak ter-roundtrip lewat validasi server (yang bisa membuat re-format menghilangkan separator ribuan).
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('transactionForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            const errors = [];

            // 1) Customer wajib (kecuali ada quotation_id terisi)
            const customerId = document.getElementById('customer_id')?.value?.trim() || '';
            const quotationId = document.getElementById('quotation_id')?.value?.trim() || '';
            if (!customerId && !quotationId) {
                errors.push('Pelanggan belum dipilih.');
            }

            // 2) Minimal satu baris item dengan product_id terisi
            const productIds = Array.from(document.querySelectorAll('#items .item-row .product_id'))
                .map(el => (el.value || '').trim())
                .filter(v => v !== '');
            if (productIds.length === 0) {
                errors.push('Minimal satu produk harus dipilih.');
            }

            if (errors.length > 0) {
                e.preventDefault();
                alert('Form belum lengkap:\n• ' + errors.join('\n• '));

                // Fokus ke field pertama yang bermasalah
                if (!customerId && !quotationId) {
                    document.getElementById('customer_search')?.focus();
                } else {
                    document.querySelector('#items .item-row .sku-search')?.focus();
                }
            }
        });
    });


    function openQuickCustomer(btn) {
        const form = document.getElementById('quickCustomerForm');
        form.classList.remove('hidden');
        
        // Position panel relative to button
        const rect = btn.getBoundingClientRect();
        form.style.top = (rect.bottom + window.scrollY + 10) + 'px';
        form.style.left = (rect.left + window.scrollX - 10) + 'px';
        
        form.querySelector('[name="name"]').focus();
    }

    function closeQuickCustomer() {
        document.getElementById('quickCustomerForm').classList.add('hidden');
        document.getElementById('customerForm').reset();
    }

    $('#customerForm').on('submit', function(e){
        e.preventDefault();
        let form = $(this);

        $.ajax({
            url: form.attr('action'),
            method: "POST",
            data: form.serialize(),
            success: function(res){
                // Fill context fields
                $('#customer_id').val(res.id);
                $('#customer_search').val(res.name);

                // Clear dropdown and close
                $('.customer-dropdown').html('');
                closeQuickCustomer();
                if (window.reloadShippingAddress) window.reloadShippingAddress();

                // Fetch address
                fetch('/erp/customers/address/' + res.id)
                    .then(res => res.json())
                    .then(data => {
                        let addrField = document.getElementById('shipping_address');
                        if (addrField) {
                            addrField.value = data.shipping_address ?? '';
                        }
                    });
            },
            error: function(xhr){
                alert('Gagal menyimpan pelanggan: ' + (xhr.responseJSON?.message || 'Error tidak diketahui'));
            }
        });
    });


    // === GLOBAL: Load Items from SO API ===
    function loadItemsFromSO(items) {
        const tbody = document.getElementById('items');
        if (!tbody) return;

        console.log("Loading items into transaction form...", items);
        tbody.innerHTML = '';

        items.forEach((item, index) => {
            // 1. Render Parent Row
            const isBundle = item.type === 'bundle';
            const parentId = `parent-${index}`;

            let rowHtml = `
            <tr class="border-t item-row ${isBundle ? 'bundle-parent' : ''}" data-row-id="${parentId}">
                <td class="p-2">
                    <div class="relative">
                        <input type="text" class="sku-search w-full border border-gray-300 rounded px-3 py-2 font-mono text-xs" value="${item.sku || (item.product?.sku || '')}">
                        <div class="product-dropdown"></div>
                    </div>
                    <input type="hidden" name="items[${index}][product_id]" class="product_id" value="${item.product_id}">
                    <input type="hidden" name="items[${index}][sales_order_item_id]" value="${item.sales_order_item_id || item.id || ''}">
                </td>
                <td class="p-2">
                    <input type="text" name="items[${index}][description]" class="description w-full border border-gray-300 rounded px-3 py-2" value="${item.description || item.name || (item.product?.name || '')}">
                </td>
                <td class="p-2 text-center stock">
                    <span class="stock-info font-bold text-gray-600">-</span>
                </td>
                <td class="p-2">
                    <select name="items[${index}][unit_id]" class="unit-select w-full border border-gray-300 rounded px-2 py-2 text-xs">
                        <option value="${item.unit_id || ''}">${item.unit_name || (isBundle ? 'set' : 'pcs')}</option>
                    </select>
                </td>
                <td class="p-2">
                    <input type="number" name="items[${index}][qty]" value="${item.qty}" class="qty w-full border border-gray-300 rounded px-3 py-2 text-right">
                </td>
                <td class="p-2">
                    <input type="text" inputmode="numeric" name="items[${index}][unit_price]" value="${formatIDR(item.unit_price || item.price)}" class="price rupiah-input w-full border border-gray-300 rounded px-3 py-2 text-right">
                </td>
                <td class="p-2">
                    <div class="flex gap-2">
                        <select name="items[${index}][discount_type]" class="discount-type border border-gray-300 rounded px-2 py-2 w-16 bg-gray-50 text-xs">
                            <option value="percent" ${item.discount_type !== 'nominal' ? 'selected' : ''}>%</option>
                            <option value="nominal" ${item.discount_type === 'nominal' ? 'selected' : ''}>Rp</option>
                        </select>
                        <input type="text" name="items[${index}][discount_value]" class="discount-value border border-gray-300 rounded px-3 py-2 w-full text-right text-xs" value="${formatIDR(item.discount_value || item.line_discount || 0)}">
                    </div>
                </td>
                <td class="p-2 text-right font-medium itemSubtotal text-gray-900">0</td>
                <td class="p-2 text-center">
                    <button type="button" class="remove-row text-red-500 hover:text-red-700" onclick="this.closest('tr').remove(); recalculate();">✕</button>
                </td>
            </tr>
        `;
            tbody.insertAdjacentHTML('beforeend', rowHtml);

            // 2. Render Component Rows if any
            if (item.components && item.components.length > 0) {
                item.components.forEach(comp => {
                    let compHtml = `
                    <tr class="bundle-child" data-parent-id="${parentId}" data-qty-required="${comp.qty_required}">
                        <td class="p-2">
                            <input type="text" class="sku w-full border-none bg-transparent font-mono text-xs" readonly value="↳ ${comp.sku}">
                        </td>
                        <td class="p-2">
                            <input type="text" class="w-full border-none bg-transparent italic text-gray-600" value="${comp.name}" readonly>
                        </td>
                        <td class="p-2 text-center stock">
                            <span class="stock-info font-bold text-gray-600">-</span>
                        </td>
                        <td class="p-2 text-center text-xs text-gray-400 italic">pcs</td>
                        <td class="p-2 text-right">
                            <input type="number" class="comp-qty w-full border-none bg-transparent text-right text-gray-600" value="${comp.qty}" readonly>
                        </td>
                        <td class="p-2 text-center text-gray-400 italic">-</td>
                        <td class="p-2 text-center text-gray-400 italic">-</td>
                        <td class="p-2 text-center text-gray-400 italic">-</td>
                        <td class="p-2"></td>
                    </tr>
                `;
                    tbody.insertAdjacentHTML('beforeend', compHtml);

                    // Fetch stock for component
                    let lastChild = tbody.lastElementChild;
                    if (typeof updateStockInfo === 'function') updateStockInfo(lastChild, comp.product_id);
                });
            }

            // Fetch stock for parent
            let lastParent = tbody.querySelector(`[data-row-id="${parentId}"]`);
            if (typeof updateStockInfo === 'function') updateStockInfo(lastParent, item.product_id);
        });

        if (typeof recalculate === 'function') recalculate();
    }
</script>

@include('erp.sales._partials.promo-form-script')
