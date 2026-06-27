@extends('layouts.erp')

@section('content')
@php $fromWhSelected = old('from_warehouse_id', \App\Core\Inventory\Warehouse::defaultId()); @endphp

<div class="max-w-6xl p-6" x-data="transferForm()">

    <div class="flex items-center justify-between mb-6">
        <h2 class="text-2xl font-bold text-gray-800">Tambah Transfer</h2>
        <a href="{{ route('inventory.transfers.index') }}" class="text-gray-500 hover:text-gray-700">
            &larr; Kembali ke Daftar
        </a>
    </div>

    <form method="POST" action="{{ route('inventory.transfers.store') }}" class="bg-white p-6 rounded-lg shadow">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tanggal</label>
                <input type="date" name="date" value="{{ date('Y-m-d') }}"
                    class="border w-full p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Dari Gudang</label>
                <select name="from_warehouse_id" x-model="fromWarehouse" @change="refreshAllStock()"
                    class="border w-full p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Ke Gudang</label>
                <select name="to_warehouse_id"
                    class="border w-full p-2 rounded focus:ring-2 focus:ring-blue-500 outline-none">
                    @foreach($warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="mb-4">
            <h3 class="text-lg font-semibold text-gray-700 mb-2">Items</h3>
            <table class="w-full border rounded">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Produk</th>
                        <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">SKU</th>
                        <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Tersedia</th>
                        <th class="p-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-32">Qty</th>
                        <th class="p-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider w-20"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <template x-for="(row, idx) in rows" :key="idx">
                        <tr>
                            {{-- Produk: live-search --}}
                            <td class="p-3 align-top">
                                <div class="relative" @click.outside="row.showDrop = false">
                                    <input type="text" x-model="row.label" autocomplete="off"
                                           @input.debounce.300ms="searchRow(idx)"
                                           @focus="row.showDrop = true"
                                           placeholder="Cari produk (SKU / nama)..."
                                           class="border p-2 w-full rounded focus:ring-2 focus:ring-blue-500 outline-none">
                                    <input type="hidden" name="products[]" :value="row.product_id">
                                    <div x-show="row.showDrop && row.results.length > 0" x-cloak
                                         class="absolute z-30 bg-white border border-gray-200 w-full mt-1 rounded-lg shadow-lg max-h-56 overflow-y-auto">
                                        <template x-for="p in row.results" :key="p.id">
                                            <div @click="pickRow(idx, p)"
                                                 class="px-3 py-2 text-sm hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0">
                                                <span class="font-bold text-blue-600" x-text="p.sku"></span>
                                                <span class="text-gray-600 ml-1" x-text="p.name"></span>
                                            </div>
                                        </template>
                                    </div>
                                    <div x-show="row.showDrop && row.query.length >= 2 && row.results.length === 0 && !row.loading" x-cloak
                                         class="absolute z-30 bg-white border border-gray-200 w-full mt-1 rounded-lg shadow-lg px-3 py-2 text-sm text-gray-400">
                                        Produk tidak ditemukan
                                    </div>
                                </div>
                            </td>
                            <td class="p-3 text-gray-600 font-mono" x-text="row.sku"></td>
                            <td class="p-3 font-bold" :class="(parseFloat(row.qty) || 0) > row.stock ? 'text-red-600' : 'text-green-600'"
                                x-text="Number(row.stock).toLocaleString('id-ID')"></td>
                            <td class="p-3">
                                <input type="number" name="qty[]" step="0.0001" min="0"
                                       x-model="row.qty" @input="clampQty(idx)"
                                       class="border p-2 w-full rounded focus:ring-2 focus:ring-blue-500 outline-none">
                            </td>
                            <td class="p-3 text-center">
                                <button type="button" @click="removeRow(idx)" class="text-red-500 hover:text-red-700">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <button type="button" @click="addRow()"
            class="mb-8 flex items-center text-blue-600 hover:text-blue-800 font-medium transition">
            <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Tambah Item
        </button>

        <div class="flex justify-end gap-3 pt-6 border-t font-semibold">
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-2 rounded shadow transition">
                Simpan Draf
            </button>
        </div>
    </form>
</div>

<script>
    function transferForm() {
        return {
            fromWarehouse: @js((string) $fromWhSelected),
            rows: [ this.blankRow() ],

            blankRow() {
                return { product_id: '', label: '', query: '', sku: '', stock: 0, qty: '', results: [], showDrop: false, loading: false };
            },

            addRow() { this.rows.push(this.blankRow()); },

            removeRow(idx) {
                if (this.rows.length > 1) {
                    this.rows.splice(idx, 1);
                } else {
                    this.rows[idx] = this.blankRow();
                }
            },

            async searchRow(idx) {
                const row = this.rows[idx];
                // Mengetik = batalkan pilihan sebelumnya.
                row.product_id = ''; row.sku = ''; row.stock = 0;
                row.query = (row.label || '').trim();
                if (row.query.length < 2) { row.results = []; return; }
                row.loading = true;
                try {
                    const res = await fetch(`/erp/api/products/search?q=${encodeURIComponent(row.query)}`);
                    row.results = await res.json();
                    row.showDrop = true;
                } catch (e) {
                    row.results = [];
                } finally {
                    row.loading = false;
                }
            },

            async pickRow(idx, p) {
                const row = this.rows[idx];
                row.product_id = p.id;
                row.label = `${p.sku} – ${p.name}`;
                row.sku = p.sku;
                row.results = [];
                row.showDrop = false;
                await this.loadStock(idx);
            },

            async loadStock(idx) {
                const row = this.rows[idx];
                if (!row.product_id || !this.fromWarehouse) { row.stock = 0; return; }
                try {
                    const res = await fetch(`/erp/inventory/product-stock/${row.product_id}/${this.fromWarehouse}`);
                    const data = await res.json();
                    row.stock = parseFloat(data.stock) || 0;
                } catch (e) {
                    row.stock = 0;
                }
            },

            async refreshAllStock() {
                for (let i = 0; i < this.rows.length; i++) {
                    if (this.rows[i].product_id) await this.loadStock(i);
                }
            },

            clampQty(idx) {
                const row = this.rows[idx];
                const qty = parseFloat(row.qty) || 0;
                if (row.product_id && qty > row.stock) {
                    alert('Stok tidak cukup! Tersedia: ' + row.stock);
                    row.qty = row.stock;
                }
            },
        };
    }
</script>
@endsection
