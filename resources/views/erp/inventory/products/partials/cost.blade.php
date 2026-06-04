<div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full">
    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">Harga Beli (Cost)</h3>
    </div>
    <div class="p-6 flex-1 flex flex-col space-y-6">
        @if(in_array($product->sale_type, ['service', 'non_stock']))

            <form action="{{ route('inventory.products.cost.store', $product->id) }}" method="POST"
                class="bg-indigo-50 p-4 rounded-lg border border-indigo-100 space-y-3">
                @csrf
                <div>
                    <label class="text-sm font-semibold text-gray-700">
                        Harga Beli (Cost)
                    </label>

                    <input type="text" name="cost_price" value="{{ number_format($product->cost_price ?? 0, 0, ',', '.') }}"
                        class="mt-2 w-full border border-gray-300 rounded-lg px-3 py-2 rupiah-input"
                        placeholder="Masukkan harga beli">
                </div>
                <button type="submit"
                    class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold text-sm hover:bg-indigo-700 transition">Update
                    Cost</button>
            </form>

        @elseif ($units->count() > 0)
            <form action="{{ route('inventory.products.cost.store', $product->id) }}" method="POST"
                class="bg-indigo-50 p-4 rounded-lg border border-indigo-100 space-y-3">
                @csrf
                <div>
                    <label class="block text-xs font-bold text-indigo-600 uppercase mb-1">Pilih Unit</label>
                    <select name="unit_name" class="w-full border border-indigo-200 rounded-lg px-3 py-2 text-sm bg-white"
                        required>
                        @foreach ($units as $u)
                            <option value="{{ $u->unit_name }}">{{ $u->unit_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-indigo-600 uppercase mb-1">Harga Beli (Cost)</label>
                    <input type="text" name="cost" placeholder="0"
                        class="w-full border border-indigo-200 rounded-lg px-3 py-2 text-sm rupiah-input" required>
                </div>
                <button type="submit"
                    class="w-full bg-indigo-600 text-white py-2 rounded-lg font-bold text-sm hover:bg-indigo-700 transition">Update
                    / Tambah Cost</button>
            </form>
        @else
            <div class="p-4 bg-yellow-50 text-yellow-700 text-sm rounded-lg border border-yellow-200">
                Simpan Units terlebih dahulu sebelum mengatur cost.
            </div>
        @endif

        @if(!in_array($product->sale_type, ['service', 'non_stock']))
            <div class="flex-1 overflow-auto">
                <table class="w-full text-xs text-left border rounded-lg">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="p-2 border">Unit</th>
                            <th class="p-2 border text-right">Harga Beli</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($costs as $c)
                            <tr>
                                <td class="p-2 border font-medium text-gray-700">{{ $c->unit_name }}</td>
                                <td class="p-2 border text-right font-bold text-indigo-600">
                                    {{ rupiah($c->cost) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="p-4 text-center text-gray-400 italic">Belum ada cost diatur.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>