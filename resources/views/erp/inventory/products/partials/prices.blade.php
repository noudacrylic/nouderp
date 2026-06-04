@if(in_array($product->type, ['service', 'non_stock']))
    <div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">HARGA JUAL BASE</h3>
        </div>
        <form method="POST" action="{{ route('inventory.products.price.store', $product->id) }}"
            class="p-6 flex-1 flex flex-col justify-center">
            @csrf
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Harga Jual Dasar</label>
                <input type="text" name="price" value="{{ number_format($product->base_price ?? 0, 0, ',', '.') }}"
                    class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition bg-white text-lg font-mono rupiah-input">
            </div>
            <div class="mt-6 pt-4 border-t border-gray-100 text-right">
                <button type="submit"
                    class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition shadow-md w-full">
                    Simpan Harga
                </button>
            </div>
        </form>
    </div>
@endif

@if(!in_array($product->type, ['service', 'non_stock']))
    <div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">Harga Jual Multi-Unit</h3>
        </div>
        <div class="p-6 flex-1 flex flex-col space-y-6">
            @if ($units->count() > 0)
                <form action="{{ route('inventory.products.price.store', $product->id) }}" method="POST"
                    class="bg-blue-50 p-4 rounded-lg border border-blue-100 space-y-3">
                    @csrf
                    <div>
                        <label class="block text-xs font-bold text-blue-600 uppercase mb-1">Pilih Unit</label>
                        <select name="unit_name" class="w-full border border-blue-200 rounded-lg px-3 py-2 text-sm bg-white"
                            required>
                            @foreach ($units as $u)
                                <option value="{{ $u->unit_name }}">{{ $u->unit_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-blue-600 uppercase mb-1">Harga Jual</label>
                        <input type="text" name="price" placeholder="0"
                            class="w-full border border-blue-200 rounded-lg px-3 py-2 text-sm rupiah-input" required>
                    </div>
                    <button type="submit"
                        class="w-full bg-blue-600 text-white py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition">Update
                        / Tambah Harga</button>
                </form>
            @else
                <div class="p-4 bg-yellow-50 text-yellow-700 text-sm rounded-lg border border-yellow-200">
                    Simpan Units terlebih dahulu sebelum mengatur harga.
                </div>
            @endif

            <div class="flex-1 overflow-auto">
                <table class="w-full text-xs text-left border rounded-lg">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="p-2 border">Unit</th>
                            <th class="p-2 border text-right">Harga Jual</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($prices as $p)
                            <tr>
                                <td class="p-2 border font-medium text-gray-700">{{ $p->unit_name }}</td>
                                <td class="p-2 border text-right font-bold text-blue-600">
                                    {{ rupiah($p->price) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="p-4 text-center text-gray-400 italic">Belum ada harga diatur.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif