@php
    $action = $action ?? '';
    $method = $method ?? 'POST';
    $type = $type ?? 'quotation';

    // Handle old items from validation errors, otherwise use existing model items, or empty collection
    $items = collect();
    if (old('items')) {
        foreach (old('items') as $oldItem) {
            $items->push((object) [
                'product_id' => $oldItem['product_id'] ?? null,
                'qty' => $oldItem['qty'] ?? 1,
                'unit_price' => $oldItem['unit_price'] ?? 0,
                'discount_type' => $oldItem['discount_type'] ?? 'nominal',
                'discount_value' => $oldItem['discount_value'] ?? 0,
                'product' => \App\Core\Inventory\Product::find($oldItem['product_id'] ?? 0)
            ]);
        }
    } else {
        $items = $items ?? ($quotation?->items ?? ($so?->items ?? collect()));
    }
@endphp

<style>
    textarea {
        resize: vertical;
        min-height: 120px;
    }
</style>

<form id="quotationForm" method="POST" action="{{ $action }}">
    @csrf
    @if($method == 'PUT')
        @method('PUT')
    @endif

    <div class="bg-white rounded-lg shadow p-6 space-y-6">

        <!-- HEADER SECTION -->
        @if(isset($quotations))
            <div class="grid grid-cols-2 gap-4 mb-4">
                {{-- PILIH PENAWARAN --}}
                <div>
                    <label class="block text-sm text-gray-600 mb-1">
                        Buat dari Penawaran
                    </label>

                    <select id="quotation_select" class="border rounded w-full p-2">
                        <option value="">-- Pilih Penawaran --</option>
                        @foreach($quotations as $q)
                            <option value="{{ $q->id }}">
                                {{ $q->customer->name ?? '-' }} - {{ $q->quotation_number }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- NOMOR PO --}}
                <div>
                    <label class="block text-sm text-gray-600 mb-1">
                        Nomor PO Customer
                    </label>

                    <input type="text" name="customer_po_number" class="border rounded w-full p-2"
                        placeholder="Masukkan nomor PO customer"
                        value="{{ old('customer_po_number', $so->customer_po_number ?? '') }}">
                </div>
            </div>
        @endif

        <div class="grid grid-cols-4 gap-6 mb-8">
            <!-- Nomor -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nomor</label>
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="autoNumber" checked class="h-4 w-4">
                    <input type="text" name="quotation_number" id="quotationNumber" placeholder="Auto" disabled
                        class="w-full border rounded px-3 py-2 text-sm disabled:bg-gray-100">
                </div>
            </div>

            <!-- Customer -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Customer</label>
                <div class="flex gap-2">
                    <select name="customer_id" class="customer-select w-full border rounded px-3 py-2 text-sm">
                        <option value=""></option>
                        @php
                            $selectedCustomer = old('customer_id', $quotation->customer_id ?? ($so->customer_id ?? ''));
                        @endphp
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" {{ $selectedCustomer == $customer->id ? 'selected' : '' }}>
                                {{ $customer->name }}
                            </option>
                        @endforeach
                    </select>
                    <button type="button" onclick="openCustomerModal()"
                        class="bg-blue-500 text-white px-3 rounded text-sm">+</button>
                </div>
            </div>

            <!-- Warehouse -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Warehouse</label>
                <select name="warehouse_id" class="w-full border rounded px-3 py-2 text-sm">
                    @php
                        $selectedWarehouse = old('warehouse_id', $quotation->warehouse_id ?? ($so->warehouse_id ?? \App\Core\Inventory\Warehouse::defaultId()));
                    @endphp
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}" {{ $selectedWarehouse == $wh->id ? 'selected' : '' }}>
                            {{ $wh->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Tanggal -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                @php
                    $dateField = $type == 'quotation' ? 'quotation_date' : ($type == 'invoice' ? 'invoice_date' : 'order_date');
                    $dateValue = old($dateField, $quotation->$dateField ?? ($so->$dateField ?? date('Y-m-d')));
                @endphp
                <input type="date" name="{{ $dateField }}" value="{{ $dateValue }}"
                    class="w-full border rounded px-3 py-2 text-sm">
            </div>
        </div>

        <!-- ITEMS TABLE -->
        <div class="mb-4">
            <div class="flex justify-between items-center mb-2">
                <h3 class="text-sm font-semibold text-gray-600">Items</h3>
                <button type="button" onclick="addItem()"
                    class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                    <span>+ Add item</span>
                </button>
            </div>

            <table class="w-full border border-gray-200 rounded-lg">
                <thead class="bg-gray-50 text-sm text-gray-600">
                    <tr>
                        <th class="p-4 text-left">Product</th>
                        <th class="p-4 text-left w-24">Qty</th>
                        <th class="p-4 text-left w-40">Price</th>
                        <th class="p-4 text-left w-40">Discount</th>
                        <th class="p-4 text-right w-40">Total</th>
                        <th class="p-4 w-10"></th>
                    </tr>
                </thead>
                <tbody id="items">
                    @if($items->isNotEmpty())
                        @foreach($items as $index => $item)
                            <tr class="border-t">
                                <td class="p-4">
                                    <input type="hidden" name="items[{{$index}}][product_id]" value="{{ $item->product_id }}">
                                    <div class="font-medium text-gray-900">{{ $item->product->name ?? '-' }}</div>
                                    <div class="text-xs text-gray-500">{{ $item->product->sku ?? '' }}</div>
                                </td>
                                <td class="p-4">
                                    <input type="number" name="items[{{$index}}][qty]" value="{{ $item->qty }}"
                                        class="qty w-full border rounded px-3 py-2 text-right">
                                </td>
                                <td class="p-4">
                                    <input type="number" name="items[{{$index}}][unit_price]" value="{{ $item->unit_price }}"
                                        class="price w-full border rounded px-3 py-2 text-right">
                                </td>
                                <td class="p-4">
                                    <div class="flex gap-2">
                                        <select name="items[{{$index}}][discount_type]"
                                            class="discount-type border rounded px-2 py-2 w-16 bg-gray-50">
                                            <option value="nominal" @if(($item->discount_type ?? 'nominal') == 'nominal') selected
                                            @endif>Rp</option>
                                            <option value="percent" @if(($item->discount_type ?? '') == 'percent') selected
                                            @endif>%</option>
                                        </select>
                                        <input type="number" name="items[{{$index}}][discount_value]"
                                            class="discount-value border rounded px-3 py-2 w-full text-right"
                                            value="{{ $item->discount_value ?? 0 }}">
                                    </div>
                                </td>
                                <td class="p-4 text-right font-medium itemSubtotal">0</td>
                                <td class="p-4 text-center">
                                    <button type="button" onclick="this.closest('tr').remove(); calculate();"
                                        class="text-red-500">✕</button>
                                </td>
                            </tr>
                        @endforeach
                    @else
                        {{-- First Row --}}
                        <tr class="border-t">
                            <td class="p-4">
                                <select name="items[0][product_id]" class="product-select w-full border rounded px-3 py-2">
                                    <option value=""></option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->id }}" data-price="{{ $product->price ?? 0 }}"
                                            data-sku="{{ $product->sku }}">
                                            {{ $product->sku }} - {{ $product->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="p-4">
                                <input type="number" name="items[0][qty]" value="1"
                                    class="qty w-full border rounded px-3 py-2 text-right">
                            </td>
                            <td class="p-4">
                                <input type="number" name="items[0][unit_price]" value="0"
                                    class="price w-full border rounded px-3 py-2 text-right">
                            </td>
                            <td class="p-4">
                                <div class="flex gap-2">
                                    <select name="items[0][discount_type]"
                                        class="discount-type border rounded px-2 py-2 w-16 bg-gray-50">
                                        <option value="nominal">Rp</option>
                                        <option value="percent">%</option>
                                    </select>
                                    <input type="number" name="items[0][discount_value]"
                                        class="discount-value border rounded px-3 py-2 w-full text-right" value="0">
                                </div>
                            </td>
                            <td class="p-4 text-right font-medium itemSubtotal">0</td>
                            <td class="p-4 text-center">
                                <button type="button" onclick="this.closest('tr').remove(); calculate();"
                                    class="text-red-500">✕</button>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        <!-- SUMMARY SECTION -->
        <div class="grid grid-cols-2 gap-8 pt-6">
            <div>
                <label class="block text-sm font-medium mb-1 text-gray-700">Catatan</label>
                <textarea name="notes" class="w-full border rounded px-3 py-2 text-sm"
                    placeholder="Tambahkan catatan di sini..."></textarea>
            </div>

            <div class="flex justify-end">
                <div class="w-full max-w-sm space-y-3 text-sm">
                    <div class="flex justify-between text-gray-600">
                        <span>Subtotal</span>
                        <span id="subtotal">0</span>
                    </div>
                    <div class="flex justify-between items-center text-gray-600">
                        <span>Global Discount</span>
                        <div class="flex gap-2">
                            @php
                                $gDiscType = old('global_discount_type', $quotation->global_discount_type ?? ($so->global_discount_type ?? 'nominal'));
                                $gDiscVal = old('global_discount_value', $quotation->global_discount_value ?? ($so->global_discount_value ?? 0));
                            @endphp
                            <select name="global_discount_type" id="globalDiscountType"
                                class="border rounded px-2 py-1 w-20 bg-white">
                                <option value="nominal" {{ $gDiscType == 'nominal' ? 'selected' : '' }}>Rp</option>
                                <option value="percent" {{ $gDiscType == 'percent' ? 'selected' : '' }}>%</option>
                            </select>
                            <input type="number" step="0.01" name="global_discount_value" id="globalDiscount"
                                value="{{ $gDiscVal }}" class="border rounded px-2 py-1 w-28 text-right bg-white">
                        </div>
                    </div>
                    <div class="flex justify-between items-center text-gray-600">
                        <span>Shipping</span>
                        <input type="number" step="0.01" name="shipping_cost" id="shipping"
                            value="{{ old('shipping_cost', $quotation->shipping_charge ?? ($so->shipping_cost ?? 0)) }}"
                            class="border rounded px-2 py-1 w-28 text-right bg-white">
                    </div>
                    <div class="flex justify-between items-center text-gray-600">
                        <span>PPN (%)</span>
                        <input type="number" step="0.01" name="ppn_percent" id="ppn"
                            value="{{ old('ppn_percent', $quotation->ppn_percent ?? ($so->ppn_percent ?? 0)) }}"
                            class="border rounded px-2 py-1 w-28 text-right bg-white">
                    </div>
                    <hr class="border-gray-200">
                    <div class="flex justify-between font-bold text-lg text-blue-900">
                        <span>GRAND TOTAL</span>
                        <span id="grandTotal">0</span>
                    </div>
                </div>
            </div>
        </div>

        @php
            $cancelRoute = match($type) {
                'quotation'   => route('sales.quotations.index'),
                'sales_order' => route('sales.orders.index'),
                'invoice'     => route('sales.invoices.index'),
                default       => url('/'),
            };
            $isQuotation = $type === 'quotation';
        @endphp
        <div class="flex justify-end gap-2 pt-6">
            <a href="{{ $cancelRoute }}"
                class="px-4 py-2 border rounded text-sm font-bold text-gray-600 hover:bg-gray-50 transition">
                Batal
            </a>
            @unless($isQuotation)
                <button type="submit" name="_after_save" value=""
                    class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded text-sm font-bold transition">
                    Draft
                </button>
            @endunless
            <button type="submit" name="_after_save" value="{{ $isQuotation ? '' : 'post' }}"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm font-bold transition">
                Simpan
            </button>
            <button type="submit" name="_after_save" value="print"
                class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm font-bold transition">
                Print
            </button>
        </div>
    </div>
</form>

{{-- MODAL CUSTOMER --}}
<div id="customerModal" class="fixed inset-0 bg-black bg-opacity-40 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-lg p-6 w-96">
        <h2 class="text-lg font-semibold mb-4">Tambah Customer</h2>
        <input id="customerName" type="text" placeholder="Nama customer" class="w-full border rounded px-3 py-2 mb-4">
        <div class="flex justify-end gap-2">
            <button type="button" onclick="closeCustomerModal()" class="px-4 py-2 border rounded">Batal</button>
            <button type="button" onclick="saveCustomer()"
                class="bg-blue-600 text-white px-4 py-2 rounded">Simpan</button>
        </div>
    </div>
</div>

<script>
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

        document.addEventListener('input', calculate);

        if (document.querySelector(".customer-select")) {
            new TomSelect(".customer-select", { create: false, maxItems: 1, placeholder: "Cari customer...", dropdownParent: 'body' });
        }

        initProductSelects();

        calculate();
    });

    function initProductSelects() {
        document.querySelectorAll('.product-select').forEach(function (el) {
            if (!el.classList.contains('tomselected')) {
                new TomSelect(el, {
                    create: false,
                    maxItems: 1,
                    placeholder: "Cari produk / SKU...",
                    allowEmptyOption: true,
                    searchField: ['sku', 'text'],
                    dropdownParent: 'body',
                    render: {
                        option: function (data, escape) {
                            if (!data.value) return '';
                            return `<div class="flex justify-between"><span>${escape(data.sku || '')}</span><span class="text-gray-500 text-sm">${escape(data.text)}</span></div>`;
                        }
                    }
                });
            }
        });
    }

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('product-select')) {
            let option = e.target.selectedOptions[0];
            if (option) {
                let price = option.dataset.price || 0;
                let row = e.target.closest('tr');
                if (row) {
                    row.querySelector('.price').value = price;
                    calculate();
                }
            }
        }
    });

    function addItem() {
        let index = document.querySelectorAll('#items tr').length;
        let products = @json($products ?? []);
        let row = `
            <tr class="border-t">
                <td class="p-4">
                    <select name="items[${index}][product_id]" class="product-select w-full border rounded px-3 py-2">
                        <option value=""></option>
                        ${products.map(p => `<option value="${p.id}" data-price="${p.price ?? 0}" data-sku="${p.sku}">${p.sku} - ${p.name}</option>`).join('')}
                    </select>
                </td>
                <td class="p-4 text-right"><input type="number" name="items[${index}][qty]" value="1" class="qty w-full border rounded px-3 py-2 text-right"></td>
                <td class="p-4 text-right"><input type="number" name="items[${index}][unit_price]" value="0" class="price w-full border rounded px-3 py-2 text-right"></td>
                <td class="p-4">
                    <div class="flex gap-2">
                        <select name="items[${index}][discount_type]" class="discount-type border rounded px-2 py-2 w-16"><option value="nominal">Rp</option><option value="percent">%</option></select>
                        <input type="number" name="items[${index}][discount_value]" class="discount-value border rounded px-3 py-2 w-full text-right" value="0">
                    </div>
                </td>
                <td class="p-4 text-right font-medium itemSubtotal">0</td>
                <td class="p-4 text-center"><button type="button" onclick="this.closest('tr').remove(); calculate();" class="text-red-500">✕</button></td>
            </tr>
        `;
        document.getElementById('items').insertAdjacentHTML('beforeend', row);
        initProductSelects();
        calculate();
    }

    function formatRupiah(number) {
        return number.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    function calculate() {
        let subtotal = 0;
        document.querySelectorAll('#items tr').forEach(row => {
            let qty = parseFloat(row.querySelector('.qty')?.value) || 0;
            let price = parseFloat(row.querySelector('.price')?.value) || 0;
            let discVal = parseFloat(row.querySelector('.discount-value')?.value) || 0;
            let discType = row.querySelector('.discount-type')?.value;
            let rowSub = qty * price;
            if (discType === 'percent') rowSub -= (rowSub * discVal / 100);
            else rowSub -= discVal;
            if (row.querySelector('.itemSubtotal')) row.querySelector('.itemSubtotal').innerText = formatRupiah(rowSub);
            subtotal += rowSub;
        });
        if (document.getElementById('subtotal')) document.getElementById('subtotal').innerText = formatRupiah(subtotal);
        let ship = parseFloat(document.getElementById('shipping')?.value) || 0;
        let gDiscVal = parseFloat(document.getElementById('globalDiscount')?.value) || 0;
        let gDiscType = document.getElementById('globalDiscountType')?.value;
        if (gDiscType === 'percent') gDiscVal = subtotal * gDiscVal / 100;
        let tax = subtotal * (parseFloat(document.getElementById('ppn')?.value || 0) / 100);
        let gTotal = subtotal - gDiscVal + ship + tax;
        if (document.getElementById('grandTotal')) document.getElementById('grandTotal').innerText = formatRupiah(gTotal);
    }

    function openCustomerModal() { document.getElementById('customerModal').classList.replace('hidden', 'flex'); }
    function closeCustomerModal() { document.getElementById('customerModal').classList.replace('flex', 'hidden'); }
    function saveCustomer() {
        let name = document.getElementById('customerName').value;
        fetch('/erp/customers/store-ajax', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
            body: JSON.stringify({ name: name })
        }).then(res => res.json()).then(data => {
            let select = document.querySelector('.customer-select').tomselect;
            select.addOption({ value: data.id, text: data.name });
            select.setValue(data.id);
            closeCustomerModal();
        });
    }
</script>