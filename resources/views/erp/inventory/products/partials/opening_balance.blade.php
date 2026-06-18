<div class="bg-white shadow-lg rounded-xl border border-gray-100 mt-8 overflow-hidden">
    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">Perbarui Saldo Awal (Stok Awal)</h3>
    </div>
    <div class="p-6">
        <form action="{{ route('products.opening.post', $product->id) }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Opening Qty</label>
                    <input type="number" step="0.0001" name="opening_qty" value="{{ qty_value($openingQty ?? 0) }}"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                </div>

                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Opening Cost (Per Unit)</label>
                    <input type="text" name="opening_cost" value="{{ number_format($openingCost ?? 0, 0, ',', '.') }}"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition rupiah-input">
                </div>

                <div class="md:col-span-4">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Gudang</label>
                    <select name="opening_warehouse_id"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm bg-white focus:ring-2 focus:ring-blue-500 outline-none transition">
                        <option value="">-- Pilih Gudang --</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" {{ ($openingWarehouseId ?? null) == $w->id ? 'selected' : '' }}>
                                {{ $w->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <button type="submit" name="action" value="post_opening"
                        class="w-full bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 active:transform active:scale-95 transition shadow-lg shadow-blue-100">
                        Post Update
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>