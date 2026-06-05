@props(['items' => collect(), 'products' => collect(), 'mode' => 'display', 'type' => '', 'so' => null])

<div class="mb-4">
    <div class="flex justify-between items-center mb-2">
        <h3 class="text-sm font-semibold text-gray-600">
            Items
        </h3>
        @if($mode !== 'display' || $products->isNotEmpty())
            <button type="button" onclick="addItem()"
                class="text-sm text-blue-600 hover:text-blue-800 flex items-center gap-1">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Tambah item
            </button>
        @endif
    </div>

    <table id="itemsTable" class="w-full border border-gray-200 rounded-lg">
        <thead class="bg-gray-50 text-sm text-gray-600">
            <tr>
                <th class="p-4 text-left" style="width: 200px;">SKU</th>
                <th class="p-4 text-left">Nama Produk</th>
                <th class="p-4 text-center w-24">Stok</th>
                <th class="p-4 text-left w-32">Unit</th>
                <th class="p-4 text-left w-24">Qty</th>
                <th class="p-4 text-left w-40">Harga</th>
                <th class="p-4 text-left w-40">Diskon</th>
                <th class="p-4 text-right w-40">Total Baris</th>
                <th class="p-4 w-10"></th>
            </tr>
        </thead>
        <tbody id="items" class="text-sm">
            @if($items && $items->isNotEmpty())
                @foreach($items as $index => $item)
                    <tr class="border-t item-row" data-type="{{ $item->product->sale_type ?? $item->product->type ?? 'product' }}">
                        <td class="p-2">
                            <div class="relative">
                                <input name="items[{{ $index }}][sku]" type="text" class="sku-search w-full border border-gray-300 rounded px-3 py-2 font-mono text-xs" value="{{ $item->product->sku ?? '' }}">
                                <div class="product-dropdown"></div>
                            </div>
                        </td>
                        <td class="p-2">
                            <input name="items[{{ $index }}][description]" type="text" class="description w-full border border-gray-300 rounded px-3 py-2" value="{{ $item->description ?? ($item->product->name ?? '') }}">
                        </td>
                        <td class="p-2 text-center stock">
                            <span class="stock-info font-bold text-gray-600">-</span>
                        </td>
                        <td class="p-2">
                            <select name="items[{{ $index }}][unit_id]" class="unit-select w-full border border-gray-300 rounded px-2 py-2 text-xs">
                                <option value="{{ $item->unit_id }}">{{ $item->unit_name ?? ($item->product->base_unit ?? 'pcs') }}</option>
                            </select>
                        </td>
                        <td class="p-2">
                            @php
                                $qtyVal = $item->qty ?? 1;
                                if ($type == 'sales_invoice' && isset($item->qty_invoiced)) {
                                    $qtyVal = $item->qty - $item->qty_invoiced;
                                }
                            @endphp
                            <input name="items[{{ $index }}][qty]" type="number" value="{{ $qtyVal }}" class="qty w-full border border-gray-300 rounded px-3 py-2 text-right">
                        </td>
                        <td class="p-2">
                            <input name="items[{{ $index }}][unit_price]" type="text" inputmode="numeric" value="{{ number_format($item->unit_price ?? 0, 0, ',', '.') }}" class="price rupiah-input w-full border border-gray-300 rounded px-3 py-2 text-right">
                        </td>
                        <td class="p-2">
                            <div class="flex gap-2">
                                <select name="items[{{ $index }}][discount_type]" class="discount-type border border-gray-300 rounded px-2 py-2 w-16 bg-gray-50 text-xs">
                                    <option value="percent" {{ ($item->discount_type ?? 'percent') == 'percent' ? 'selected' : '' }}>%</option>
                                    <option value="nominal" {{ ($item->discount_type ?? 'percent') == 'nominal' ? 'selected' : '' }}>Rp</option>
                                </select>
                                <input name="items[{{ $index }}][discount_value]" type="text" class="discount-value border border-gray-300 rounded px-3 py-2 w-full text-right text-xs" value="{{ number_format($item->discount_value ?? 0, 0, ',', '.') }}">
                            </div>
                        </td>
                        <td class="p-2 text-right font-medium itemSubtotal text-gray-900">0</td>
                        <td class="p-2 text-center">
                            <button type="button" class="remove-row text-red-500 hover:text-red-700">✕</button>
                            <input name="items[{{ $index }}][product_id]" type="hidden" class="product_id" value="{{ $item->product_id }}">
                            @if($type == 'sales_invoice' || $type == 'invoice')
                                <input name="items[{{ $index }}][sales_order_item_id]" type="hidden" value="{{ $item->sales_order_item_id ?? (isset($item->sales_order_id) ? $item->id : '') }}">
                            @endif
                        </td>
                    </tr>
                @endforeach
            @else
                {{-- Default empty row (index 0) --}}
                <tr class="border-t item-row">
                    <td class="p-2">
                        <div class="relative">
                            <input name="items[0][sku]" type="text" class="sku-search w-full border border-gray-300 rounded px-3 py-2 font-mono text-xs" placeholder="Cari SKU...">
                            <div class="product-dropdown"></div>
                        </div>
                    </td>
                    <td class="p-2">
                        <input name="items[0][description]" type="text" class="description w-full border border-gray-300 rounded px-3 py-2" placeholder="Pilih produk via SKU...">
                    </td>
                    <td class="p-2 text-center stock">-</td>
                    <td class="p-2">
                        <select name="items[0][unit_id]" class="unit-select w-full border border-gray-300 rounded px-2 py-2 text-xs">
                            <option value="">-</option>
                        </select>
                    </td>
                    <td class="p-2">
                        <input name="items[0][qty]" type="number" value="1" class="qty w-full border border-gray-300 rounded px-3 py-2 text-right">
                    </td>
                    <td class="p-2">
                        <input name="items[0][unit_price]" type="text" inputmode="numeric" value="0" class="price rupiah-input w-full border border-gray-300 rounded px-3 py-2 text-right">
                    </td>
                    <td class="p-2">
                        <div class="flex gap-2">
                            <select name="items[0][discount_type]" class="discount-type border border-gray-300 rounded px-2 py-2 w-16 bg-gray-50 text-xs">
                                <option value="percent">%</option>
                                <option value="nominal">Rp</option>
                            </select>
                            <input name="items[0][discount_value]" type="text" value="0" class="discount-value border border-gray-300 rounded px-3 py-2 w-full text-right text-xs">
                        </div>
                    </td>
                    <td class="p-2 text-right itemSubtotal">0</td>
                    <td class="p-2 text-center">
                        <button type="button" class="remove-row text-red-500 hover:text-red-700">✕</button>
                        <input name="items[0][product_id]" type="hidden" class="product_id">
                        <input name="items[0][sales_order_item_id]" type="hidden">
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</div>

<script>
// Index starts at 1 because row index 0 already exists in the DOM
let itemIndex = {{ ($items && $items->isNotEmpty()) ? $items->count() : 1 }};

function addItemRow() {
    const table = document.querySelector('#itemsTable tbody');
    const idx   = itemIndex;

    const rowHtml = `
    <tr class="border-t item-row">
        <td class="p-2">
            <div class="relative">
                <input name="items[${idx}][sku]" type="text" class="sku-search w-full border border-gray-300 rounded px-3 py-2 font-mono text-xs" placeholder="Cari SKU...">
                <div class="product-dropdown"></div>
            </div>
        </td>
        <td class="p-2">
            <input name="items[${idx}][description]" type="text" class="description w-full border border-gray-300 rounded px-3 py-2" placeholder="Pilih produk via SKU...">
        </td>
        <td class="p-2 text-center stock">-</td>
        <td class="p-2">
            <select name="items[${idx}][unit_id]" class="unit-select w-full border border-gray-300 rounded px-2 py-2 text-xs">
                <option value="">-</option>
            </select>
        </td>
        <td class="p-2">
            <input name="items[${idx}][qty]" type="number" value="1" class="qty w-full border border-gray-300 rounded px-3 py-2 text-right">
        </td>
        <td class="p-2">
            <input name="items[${idx}][unit_price]" type="text" inputmode="numeric" value="0" class="price rupiah-input w-full border border-gray-300 rounded px-3 py-2 text-right">
        </td>
        <td class="p-2">
            <div class="flex gap-2">
                <select name="items[${idx}][discount_type]" class="discount-type border border-gray-300 rounded px-2 py-2 w-16 bg-gray-50 text-xs">
                    <option value="percent">%</option>
                    <option value="nominal">Rp</option>
                </select>
                <input name="items[${idx}][discount_value]" type="text" value="0" class="discount-value border border-gray-300 rounded px-3 py-2 w-full text-right text-xs">
            </div>
        </td>
        <td class="p-2 text-right itemSubtotal">0</td>
        <td class="p-2 text-center">
            <button type="button" class="remove-row text-red-500 hover:text-red-700">✕</button>
            <input name="items[${idx}][product_id]" type="hidden" class="product_id">
            <input name="items[${idx}][sales_order_item_id]" type="hidden">
        </td>
    </tr>`;

    table.insertAdjacentHTML('beforeend', rowHtml);
    itemIndex++;

    // Return the newly inserted row
    return table.lastElementChild;
}

function addItem() {
    const row = addItemRow();
    if (!row) return;
    const skuInput = row.querySelector('[class*="sku-search"]');
    if (skuInput) skuInput.focus();
    calculate();
}

// Delete row handler
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('remove-row')) {
        e.target.closest('tr').remove();
        calculate();
    }
});
</script>
</div>