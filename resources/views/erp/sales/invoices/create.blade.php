@extends('layouts.erp')

@section('content')

    <x-transaction-header title="Create Invoice" />

    {{-- HEADER INVOICE: 2 BARIS (STRUKTUR FINAL) --}}
    <div class="card p-4 mb-4 bg-white shadow-sm border border-gray-100">
        
        <!-- 🔹 BARIS 1: RELASI & REFERENSI -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <!-- Sales Order -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Sales Order</label>
                <select id="so_select" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none transition">
                    <option value="">Cari SO number / customer</option>
                    @foreach($salesOrders as $order)
                        <option value="{{ $order->id }}" {{ ($so && $so->id == $order->id) ? 'selected' : '' }}>
                            {{ $order->order_number }} - {{ $order->customer->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- No PO Customer -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">No. PO Customer</label>
                <input type="text" id="customer_po_header" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500" placeholder="-" readonly>
            </div>

            <!-- Uang Muka -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Uang Muka</label>
                <input type="text" id="advance_info" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 font-medium" value="Tidak ada advance" readonly>
            </div>

            <!-- Surat Jalan -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Surat Jalan</label>
                <input type="text" id="delivery_status" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 font-medium" value="Belum ada delivery" readonly>
            </div>
        </div>

        <!-- 🔹 BARIS 2: DATA TRANSAKSI UTAMA -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
            <!-- Nomor Invoice -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Nomor Invoice</label>
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="autoNumber" checked class="h-4 w-4">
                    <input type="text" name="invoice_number" id="quotationNumber" 
                        placeholder="[ Otomatis ]" 
                        disabled
                        form="transactionForm"
                        class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-400">
                </div>
            </div>

            <!-- Customer -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1 text-primary">Customer</label>
                <div id="customer_manual_container" class="{{ $so ? 'hidden' : '' }}">
                    <div class="customer-select">
                        <div class="flex">
                            <input type="text" 
                                   id="customer_search" 
                                   class="form-control w-full border border-gray-200 rounded-l-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition border-r-0" 
                                   placeholder="Cari customer..."
                                   value="{{ $so->customer->name ?? '' }}">

                            <button type="button" onclick="openQuickCustomer(this)"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 rounded-r-lg font-bold transition-colors">
                                +
                            </button>
                        </div>
                        <div class="customer-dropdown"></div>
                    </div>
                </div>
                <div id="customer_auto_container" class="{{ $so ? '' : 'hidden' }}">
                    <input type="text" id="invoice_customer_name" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-gray-50 text-gray-500 font-bold" value="{{ $so->customer->name ?? '' }}" readonly>
                </div>
                <input type="hidden" name="customer_id" id="customer_id" value="{{ $so->customer_id ?? '' }}" form="transactionForm">
            </div>

            <!-- Warehouse -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Warehouse</label>
                @php $whSelected = old('warehouse_id', $so->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId()); @endphp
                <select name="warehouse_id" id="warehouse_select" class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm bg-white" form="transactionForm">
                    @foreach($warehouses as $w)
                        <option value="{{ $w->id }}" {{ $whSelected == $w->id ? 'selected' : '' }}>
                            {{ $w->name }}
                        </option>
                    @endforeach
                </select>
                <input type="hidden" name="warehouse_id_hidden" id="warehouse_id_hidden" value="{{ $so->warehouse_id ?? '' }}">
            </div>

            <!-- Tanggal -->
            <div>
                <label class="block text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-1">Tanggal</label>
                <input type="date" name="invoice_date" value="{{ date('Y-m-d') }}" form="transactionForm"
                    class="form-control w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
            </div>

        </div>

    </div>

    {{-- Transaction Form --}}
    <div class="relative">
        <x-transaction-form type="invoice" :customers="$customers" :warehouses="$warehouses" :products="$products" :so="$so ?? null" :delivery="$delivery ?? null" action="{{ route('sales.invoices.store') }}" method="POST" />

        {{-- Hidden SO ID for the form --}}
        <input type="hidden" name="sales_order_id" id="sales_order_id_hidden" value="{{ $so->id ?? '' }}" form="transactionForm">
    </div>

@endsection

@section('styles')
    <style>
        .bundle-parent { background-color: #ffffff; font-weight: 600; }
        .bundle-child { background-color: #f0f9ff !important; font-size: 0.825rem; }
        .bundle-child input { background-color: transparent !important; border: none !important; pointer-events: none; }
        .bundle-child .sku { padding-left: 1.5rem !important; }
    </style>
@endsection

@section('scripts')
    <script>
        function formatIDR(num) {
            return Number(num).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        }

        function resetHeader() {
            $('#customer_po_header').val('-');
            $('#advance_info').val("Tidak ada advance");
            $('#delivery_status').val("Belum ada delivery");

            // Toggle manual mode
            $('#customer_manual_container').removeClass('hidden');
            $('#customer_auto_container').addClass('hidden');
            $('#invoice_customer_name').val('');
            $('#customer_search').val(''); // Clear search input
            
            $('#customer_id').val('');
            
            $('#warehouse_id_hidden').val('');
            $('#sales_order_id_hidden').val('');
        }

        function loadItemsFromSO(items) {
            const tbody = document.getElementById('items');
            if (!tbody) return;
            tbody.innerHTML = '';

            items.forEach((item, index) => {
                const isBundle = item.type === 'bundle';
                const parentId = `parent-${index}`;

                let rowHtml = `
                <tr class="border-t item-row ${isBundle ? 'bundle-parent' : ''}" data-row-id="${parentId}">
                    <td class="p-2">
                        <div class="relative">
                            <input type="text" class="sku-search w-full border border-gray-200 rounded px-3 py-2 font-mono text-xs focus:ring-1 focus:ring-blue-500" value="${item.sku || ''}">
                            <div class="product-dropdown"></div>
                        </div>
                        <input type="hidden" name="items[${index}][product_id]" class="product_id" value="${item.product_id}">
                        <input type="hidden" name="items[${index}][sales_order_item_id]" value="${item.sales_order_item_id ?? item.id}">
                    </td>
                    <td class="p-2">
                        <input type="text" name="items[${index}][description]" class="description w-full border border-gray-200 rounded px-3 py-2 text-sm" value="${item.description || item.name || ''}">
                    </td>
                    <td class="p-2 text-center stock">
                        <span class="stock-info font-bold text-gray-600">-</span>
                    </td>
                    <td class="p-2">
                        <select name="items[${index}][unit_id]" class="unit-select w-full border border-gray-200 rounded px-2 py-2 text-xs">
                            <option value="">${isBundle ? 'set' : 'pcs'}</option>
                        </select>
                    </td>
                    <td class="p-2">
                        <input type="number" name="items[${index}][qty]" value="${item.qty}" class="qty w-full border border-gray-200 rounded px-3 py-2 text-right text-sm">
                    </td>
                    <td class="p-2">
                        <input type="text" name="items[${index}][unit_price]" value="${formatIDR(item.price)}" class="price w-full border border-gray-200 rounded px-3 py-2 text-right text-sm">
                    </td>
                    <td class="p-2">
                        <div class="flex gap-2">
                            <select name="items[${index}][discount_type]" class="discount-type border border-gray-200 rounded px-2 py-2 w-16 bg-gray-50 text-xs">
                                <option value="nominal" ${item.discount_type === 'nominal' ? 'selected' : ''}>Rp</option>
                                <option value="percent" ${item.discount_type !== 'nominal' ? 'selected' : ''}>%</option>
                            </select>
                            <input type="text" name="items[${index}][discount_value]" class="discount-value border border-gray-200 rounded px-3 py-2 w-full text-right text-xs" value="${formatIDR(item.discount_value || 0)}">
                        </div>
                    </td>
                    <td class="p-2 text-right font-medium itemSubtotal text-gray-900 border-l border-gray-50">0</td>
                    <td class="p-2 text-center">
                        <button type="button" onclick="this.closest('tr').remove(); typeof recalculate === 'function' ? recalculate() : null;" class="text-red-400 hover:text-red-600 transition-colors">✕</button>
                    </td>
                </tr>
            `;
                tbody.insertAdjacentHTML('beforeend', rowHtml);

                if (item.components && item.components.length > 0) {
                    item.components.forEach(comp => {
                        let compHtml = `
                        <tr class="bundle-child" data-parent-id="${parentId}" data-qty-required="${comp.qty_required}">
                            <td class="p-2"><input type="text" class="sku w-full border-none bg-transparent font-mono text-xs" readonly value="↳ ${comp.sku}"></td>
                            <td class="p-2"><input type="text" class="w-full border-none bg-transparent italic text-gray-500" value="${comp.name}" readonly></td>
                            <td class="p-2 text-center stock"><span class="stock-info font-bold text-gray-400">-</span></td>
                            <td class="p-2 text-center text-xs text-gray-400 italic">pcs</td>
                            <td class="p-2 text-right"><input type="number" class="comp-qty w-full border-none bg-transparent text-right text-gray-400" value="${comp.qty}" readonly></td>
                            <td colspan="4"></td>
                        </tr>`;
                        tbody.insertAdjacentHTML('beforeend', compHtml);
                        if (typeof updateStockInfo === 'function') updateStockInfo(tbody.lastElementChild, comp.product_id);
                    });
                }
                if (typeof updateStockInfo === 'function') updateStockInfo(tbody.querySelector(`[data-row-id="${parentId}"]`), item.product_id);
            });

            if (typeof recalculate === 'function') recalculate();
        }

        $(document).ready(function () {
            $('#so_select').select2({ placeholder: "Cari SO number / customer", width: '100%' });

            $('#so_select').on('change', function () {
                let soId = $(this).val();
                if (!soId) { resetHeader(); return; }

                fetch('/erp/api/sales-orders/' + soId).then(res => res.json()).then(data => {
                    $('#customer_po_header').val(data.customer_po_number ?? '-');
                    $('#advance_info').val(data.advance > 0 ? "Advance: Rp " + formatIDR(data.advance) : "Tidak ada advance");
                    $('#delivery_status').val(data.delivery ? data.delivery.delivery_number : "Belum ada delivery");
                    $('#sales_order_id_hidden').val(soId);

                    // Switch to Auto mode
                    $('#customer_manual_container').addClass('hidden');
                    $('#customer_auto_container').removeClass('hidden');
                    $('#invoice_customer_name').val(data.customer_name);
                    $('#customer_id').val(data.customer_id);
                    if (window.reloadShippingAddress) window.reloadShippingAddress();
                    
                    $('#warehouse_select').val(data.warehouse_id).trigger('change');
                    $('#warehouse_id_hidden').val(data.warehouse_id);

                    loadItemsFromSO(data.items);
                    
                    if ($('#globalDiscount').length) $('#globalDiscount').val(formatIDR(data.global_discount_value || 0));
                    if ($('#globalDiscountType').length) $('#globalDiscountType').val(data.global_discount_type || 'nominal');
                    var dmSel = document.getElementById('delivery_method');
                    if (dmSel) { dmSel.value = data.delivery_method || 'kurir'; dmSel.dispatchEvent(new Event('change')); }
                    if (window.setShippingDetail) {
                        window.setShippingDetail({
                            gross: data.shipping_gross || data.shipping_cost || 0,
                            discount_type: data.shipping_discount_type || 'nominal',
                            discount_value: data.shipping_discount_value || 0,
                            courier_code: data.shipping_courier_code || '',
                            service_code: data.shipping_service_code || '',
                            service_name: data.shipping_service_name || '',
                            package_length: data.package_length || '',
                            package_width: data.package_width || '',
                            package_height: data.package_height || '',
                        });
                    } else if ($('#shipping').length) {
                        $('#shipping').val(formatIDR(data.shipping_cost || 0));
                    }
                    if ($('#expense').length) $('#expense').val(formatIDR(data.expense || 0));
                    if ($('#tax_percent').length) $('#tax_percent').val(data.ppn_percent || 0);

                    if (typeof recalculate === 'function') recalculate();
                });
            });

            // Auto-load items via API HANYA jika tabel masih kosong (mis. user buka /create polos lalu pilih SO).
            // Kalau halaman ini sudah dirender dari createFromSO (item sudah ada server-side), skip
            // supaya tidak terjadi wipe-and-rebuild yang bikin stok ber-flicker (muncul → '-' → muncul).
            const itemsRendered = !!document.querySelector('#items .item-row');
            if($('#so_select').val() && !itemsRendered) $('#so_select').trigger('change');
        });
    </script>
@endsection