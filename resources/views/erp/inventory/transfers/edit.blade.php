@extends('layouts.erp')

@section('content')

    <div class="max-w-6xl p-6">

        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">
                    Edit Transfer - {{ $transfer->number }}
                </h2>
                <span class="px-2 py-1 rounded text-xs font-bold uppercase bg-yellow-100 text-yellow-700">
                    {{ $transfer->status }}
                </span>
            </div>
            <a href="{{ route('inventory.transfers.index') }}" class="text-gray-500 hover:text-gray-700">
                &larr; Back to List
            </a>
        </div>

        <form method="POST" action="{{ route('inventory.transfers.update', $transfer->id) }}"
            class="bg-white p-6 rounded-lg shadow">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="date" value="{{ $transfer->date }}"
                        class="border w-full p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">From Warehouse</label>
                    <select name="from_warehouse_id" id="from_warehouse_id"
                        class="border w-full p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                        @foreach($warehouses as $wh)
                            <option value="{{$wh->id}}" {{ $transfer->from_warehouse_id == $wh->id ? 'selected' : '' }}>
                                {{$wh->name}}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">To Warehouse</label>
                    <select name="to_warehouse_id"
                        class="border w-full p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                        @foreach($warehouses as $wh)
                            <option value="{{$wh->id}}" {{ $transfer->to_warehouse_id == $wh->id ? 'selected' : '' }}>
                                {{$wh->name}}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-700 mb-2">Items</h3>
                <table class="w-full border rounded overflow-hidden" id="items-table">
                    <thead class="bg-gray-50 border-b">
                        <tr>
                            <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Product</th>
                            <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">SKU</th>
                            <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Available
                            </th>
                            <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-32">Qty</th>
                            <th class="p-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider w-20"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($transfer->items as $item)
                            <tr class="item-row">
                                <td class="p-3">
                                    <select name="products[]"
                                        class="border p-2 w-full rounded product-select focus:ring-2 focus:ring-blue-500 outline-none">
                                        <option value="">Select Product</option>
                                        @foreach($products as $product)
                                            <option value="{{$product->id}}" data-sku="{{$product->sku}}" {{ $item->product_id == $product->id ? 'selected' : '' }}>
                                                {{$product->name}}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="p-3 text-gray-600 font-mono sku">{{ optional($item->product)->sku }}</td>
                                <td class="p-3 text-green-600 font-bold stock">fetching...</td>
                                <td class="p-3">
                                    <input type="number" name="qty[]" step="0.0001" value="{{ (float) $item->qty }}"
                                        class="qty-input border p-2 w-full rounded focus:ring-2 focus:ring-blue-500 outline-none">
                                </td>
                                <td class="p-3 text-center">
                                    <button type="button" class="remove-row text-red-500 hover:text-red-700">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                            </path>
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <button type="button" id="add-row"
                class="mb-8 flex items-center text-blue-600 hover:text-blue-800 font-medium transition">
                <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6">
                    </path>
                </svg>
                Add Item
            </button>

            <div class="flex justify-end gap-3 pt-6 border-t font-semibold">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2 rounded shadow transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <script>
        const getStock = async (product, warehouse, row) => {
            if (product && warehouse) {
                try {
                    let res = await fetch(`/erp/inventory/product-stock/${product}/${warehouse}`);
                    let data = await res.json();
                    row.querySelector('.stock').innerText = data.stock;
                } catch (err) {
                    console.error('Error fetching stock:', err);
                    row.querySelector('.stock').innerText = '0';
                }
            } else {
                row.querySelector('.stock').innerText = '0';
            }
        };

        // Initial stock fetch for edit page
        window.addEventListener('load', () => {
            let warehouse = document.getElementById('from_warehouse_id').value;
            let rows = document.querySelectorAll('.item-row');
            rows.forEach(row => {
                let product = row.querySelector('.product-select').value;
                getStock(product, warehouse, row);
            });
        });

        document.getElementById('add-row').onclick = function () {
            let table = document.querySelector('#items-table tbody');
            let rows = document.querySelectorAll('.item-row');
            let firstRow = rows[0];
            let newRow = firstRow.cloneNode(true);

            // Reset inputs
            newRow.querySelector('select').value = '';
            newRow.querySelector('.qty-input').value = '';
            newRow.querySelector('.sku').innerText = '';
            newRow.querySelector('.stock').innerText = '0';

            table.appendChild(newRow);
        };

        document.addEventListener('change', async function (e) {
            if (e.target.classList.contains('product-select')) {
                let product = e.target.value;
                let warehouse = document.getElementById('from_warehouse_id').value;
                let row = e.target.closest('tr');

                await getStock(product, warehouse, row);
                row.querySelector('.sku').innerText = e.target.selectedOptions[0].dataset.sku || '';
            }

            if (e.target.id === 'from_warehouse_id') {
                let warehouse = e.target.value;
                let rows = document.querySelectorAll('.item-row');
                rows.forEach(row => {
                    let product = row.querySelector('.product-select').value;
                    getStock(product, warehouse, row);
                });
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.classList.contains('qty-input')) {
                let row = e.target.closest('tr');
                let stockValue = row.querySelector('.stock').innerText;
                let stock = parseFloat(stockValue === 'fetching...' ? 999999 : stockValue) || 0;
                let qty = parseFloat(e.target.value) || 0;

                if (qty > stock) {
                    alert('Insufficent stock! Available: ' + stock);
                    e.target.value = stock;
                }
            }
        });

        document.addEventListener('click', function (e) {
            let btn = e.target.closest('.remove-row');
            if (btn) {
                let row = btn.closest('tr');
                if (document.querySelectorAll('.item-row').length > 1) {
                    row.remove();
                } else {
                    row.querySelector('select').value = '';
                    row.querySelector('.qty-input').value = '';
                    row.querySelector('.sku').innerText = '';
                    row.querySelector('.stock').innerText = '0';
                }
            }
        });
    </script>

@endsection