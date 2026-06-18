@extends('layouts.erp')

@section('content')

    <div class="max-w-5xl mx-auto">

        <h2 class="text-xl font-semibold mb-4">
            Edit Penyesuaian: {{ $adjustment->number }}
        </h2>

        <form method="POST" action="{{ route('inventory.adjustments.update', $adjustment->id) }}">
            @csrf
            @method('PUT')

            @if($adjustment->status == 'posted')
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            ⚠️
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                Penyesuaian ini sudah <strong>DIPOSTING</strong>. Sekarang hanya-baca dan tidak bisa diubah.
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            <fieldset @if($adjustment->status == 'posted') disabled @endif class="space-y-6">

                <div class="bg-white border rounded-lg shadow p-6 space-y-6">

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Nomor Penyesuaian</label>
                            <input type="text" value="{{ $adjustment->number }}"
                                class="w-full border rounded px-3 py-2 bg-gray-50 text-gray-500" readonly>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Nama Penyesuaian /
                                Judul</label>
                            <input type="text" name="title" value="{{ $adjustment->title }}"
                                placeholder="Audit Bulanan / Stock Opname" class="w-full border rounded px-3 py-2">
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Tipe</label>
                            <select name="type" class="w-full border rounded px-3 py-2">
                                <option value="Stock Opname" {{ $adjustment->type == 'Stock Opname' ? 'selected' : '' }}>Stock
                                    Opname</option>
                                <option value="Stock Rusak" {{ $adjustment->type == 'Stock Rusak' ? 'selected' : '' }}>Stock
                                    Rusak</option>
                                <option value="Stock Hilang" {{ $adjustment->type == 'Stock Hilang' ? 'selected' : '' }}>Stock
                                    Hilang</option>
                                <option value="Koreksi Sistem" {{ $adjustment->type == 'Koreksi Sistem' ? 'selected' : '' }}>
                                    Koreksi Sistem</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Tanggal</label>
                            <input type="date" name="date" value="{{ $adjustment->date }}"
                                class="w-full border rounded px-3 py-2" required>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Gudang</label>
                            <select name="warehouse_id" id="warehouse_id" class="w-full border rounded px-3 py-2" required>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" {{ $adjustment->warehouse_id == $warehouse->id ? 'selected' : '' }}>
                                        {{ $warehouse->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Penanggung Jawab</label>
                            <input type="text" name="responsible" value="{{ $adjustment->responsible }}"
                                class="w-full border rounded px-3 py-2" placeholder="Nama petugas / Admin">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Catatan Header</label>
                            <input type="text" name="notes" value="{{ $adjustment->notes }}"
                                placeholder="Catatan tambahan untuk transaksi ini..."
                                class="w-full border rounded px-3 py-2">
                        </div>

                    </div>

                </div>

                <div class="bg-white border rounded-lg shadow mt-6">

                    <div class="p-4 border-b font-semibold flex justify-between items-center">
                        <span>Item Penyesuaian</span>
                        @if($adjustment->status != 'posted')
                            <button type="button" onclick="addItem()" class="text-blue-600 text-sm font-bold">+ Tambah
                                Item</button>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">

                            <thead class="bg-gray-50 border-b">
                                <tr>
                                    <th class="p-3 text-left">Produk</th>
                                    <th class="p-3 text-right w-24">Sistem</th>
                                    <th class="p-3 text-right w-32">Aktual</th>
                                    <th class="p-3 text-right w-24">Selisih</th>
                                    <th class="p-3 text-left">Catatan Baris</th>
                                    <th class="w-12"></th>
                                </tr>
                            </thead>

                            <tbody id="items-table">
                                @foreach($adjustment->items as $idx => $item)
                                    <tr class="item-row border-b last:border-0 hover:bg-gray-50 transition">
                                        <td class="p-2">
                                            <select name="items[{{ $idx }}][product_id]"
                                                class="product-select w-full border rounded px-2 py-2" required>
                                                <option value="">Pilih Produk</option>
                                                @foreach ($products as $product)
                                                    <option value="{{ $product->id }}" {{ $item->product_id == $product->id ? 'selected' : '' }}>
                                                        {{ $product->sku }} - {{ $product->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="p-2 text-right text-gray-500">
                                            <span class="system-qty">{{ format_qty($item->system_qty) }}</span>
                                            {{-- value untuk input/JS: machine-readable (titik desimal, tanpa pemisah ribuan) --}}
                                            <input type="hidden" name="items[{{ $idx }}][system]" class="system-value"
                                                value="{{ qty_value($item->system_qty) }}">
                                        </td>
                                        <td class="p-2">
                                            <input type="number" step="0.0001" name="items[{{ $idx }}][actual]"
                                                class="w-full border rounded px-2 py-2 text-right actual-input" required
                                                value="{{ qty_value($item->actual_qty) }}">
                                        </td>
                                        <td class="p-2 text-right font-bold diff-qty">
                                            {{ format_qty($item->diff_qty) }}
                                        </td>
                                        <td class="p-2">
                                            <input type="text" name="items[{{ $idx }}][notes]" value="{{ $item->notes }}"
                                                placeholder="Rusak / Hilang..." class="w-full border rounded px-2 py-2">
                                        </td>
                                        <td class="p-2 text-center text-red-500 cursor-pointer hover:bg-red-50"
                                            onclick="removeRow(this)">
                                            @if($adjustment->status != 'posted') 🗑 @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>

                        </table>
                    </div>

                </div>

            </fieldset>

            <div class="mt-6 flex justify-end gap-3">
                <a href="{{ route('inventory.adjustments.index') }}" class="btn btn-outline">Kembali ke Daftar</a>
                @if($adjustment->status != 'posted')
                    <button type="submit"
                        class="bg-blue-600 text-white px-8 py-3 rounded-lg font-bold shadow-lg hover:bg-blue-700 transition">
                        Perbarui Penyesuaian
                    </button>
                @endif
            </div>

        </form>

    </div>

    <script>
        let index = {{ $adjustment->items->count() }};

        function initTomSelect(el) {
            if (el.tomselect) return;
            new TomSelect(el, {
                create: false,
                sortField: {
                    field: "text",
                    direction: "asc"
                },
                maxOptions: null,
                dropdownParent: 'body'
            });
        }

        document.querySelectorAll('.product-select').forEach(el => initTomSelect(el));

        function addItem() {
            const table = document.getElementById('items-table');
            const rowHTML = `
                <tr class="item-row border-b last:border-0 hover:bg-gray-50 transition">
                    <td class="p-2">
                        <select name="items[${index}][product_id]" class="product-select w-full border rounded px-2 py-2" required>
                            <option value="">Select Product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">
                                    {{ $product->sku }} - {{ $product->name }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td class="p-2 text-right text-gray-500">
                        <span class="system-qty">0</span>
                        <input type="hidden" name="items[${index}][system]" class="system-value" value="0">
                    </td>
                    <td class="p-2">
                        <input type="number" step="0.0001" name="items[${index}][actual]" class="w-full border rounded px-2 py-2 text-right actual-input" required value="0">
                    </td>
                    <td class="p-2 text-right font-bold diff-qty">0</td>
                    <td class="p-2">
                        <input type="text" name="items[${index}][notes]" placeholder="Catatan per baris..." class="w-full border rounded px-2 py-2">
                    </td>
                    <td class="p-2 text-center text-red-500 cursor-pointer hover:bg-red-50" onclick="removeRow(this)">🗑</td>
                </tr>
                `;
            table.insertAdjacentHTML('beforeend', rowHTML);

            const newSelect = table.lastElementChild.querySelector('.product-select');
            initTomSelect(newSelect);

            index++;
        }

        function removeRow(btn) {
            const row = btn.closest('tr');
            if (document.querySelectorAll('.item-row').length > 1) {
                if (row.querySelector('.product-select').tomselect) {
                    row.querySelector('.product-select').tomselect.destroy();
                }
                row.remove();
            } else {
                alert('Minimal harus ada 1 item.');
            }
        }

        // Logic for fetching system stock
        document.addEventListener('change', function (e) {
            if (e.target.classList.contains('product-select')) {
                const productId = e.target.value;
                const warehouseId = document.getElementById('warehouse_id').value;
                const row = e.target.closest('tr');

                if (!productId || !warehouseId) {
                    row.querySelector('.system-qty').innerText = '0';
                    row.querySelector('.system-value').value = '0';
                    calculateDiff(row);
                    return;
                }

                row.querySelector('.system-qty').innerText = '...';

                fetch(`/erp/inventory/product-stock-adjustment?product_id=${productId}&warehouse_id=${warehouseId}`)
                    .then(res => res.json())
                    .then(data => {
                        row.querySelector('.system-qty').innerText = fmtQty(data.qty);
                        row.querySelector('.system-value').value = data.qty;
                        calculateDiff(row);
                    })
                    .catch(err => {
                        console.error(err);
                        row.querySelector('.system-qty').innerText = 'Error';
                    });
            }
        });

        // Logic for auto calculating diff
        document.addEventListener('input', function (e) {
            if (e.target.classList.contains('actual-input')) {
                calculateDiff(e.target.closest('tr'));
            }
        });

        // Format qty ala Indonesia: koma desimal, titik ribuan, desimal tampil hanya bila ada.
        function fmtQty(n) {
            return (parseFloat(n) || 0).toLocaleString('id-ID', { maximumFractionDigits: 4 });
        }

        function calculateDiff(row) {
            const systemQty = parseFloat(row.querySelector('.system-value').value) || 0;
            const actualInput = row.querySelector('.actual-input');
            const actualQty = parseFloat(actualInput.value) || 0;
            const diff = actualQty - systemQty;

            const diffEl = row.querySelector('.diff-qty');
            diffEl.innerText = diff === 0 ? '0' : (diff > 0 ? '+' : '') + fmtQty(diff);

            if (diff < 0) {
                diffEl.style.color = 'red';
            } else if (diff > 0) {
                diffEl.style.color = 'green';
            } else {
                diffEl.style.color = 'black';
            }
        }

        // Run calculation for all rows on load
        document.querySelectorAll('.item-row').forEach(row => calculateDiff(row));
    </script>

@endsection