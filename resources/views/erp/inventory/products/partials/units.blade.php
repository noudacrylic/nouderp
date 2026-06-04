<div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full">

    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">SATUAN PRODUK (UNITS)</h3>
    </div>

    <form action="{{ route('inventory.products.units.store', $product->id) }}" method="POST"
        class="p-6 flex-1 flex flex-col">
        @csrf

        <div class="space-y-4 flex-1">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">BASE UNIT (LEVEL 0)</label>
                <input type="text" name="base_unit" value="{{ $product->base_unit }}" placeholder="pcs"
                    class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition"
                    required>
            </div>

            <div id="units-wrapper" class="space-y-3 pt-4 border-t border-gray-100">
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">ADDITIONAL UNITS
                    (MULTI-UNIT)</label>
                @foreach ($units as $u)
                    @if ($u->level > 0)
                        <div class="flex gap-2 items-center unit-row animate-fadeIn">
                            <input type="text" name="units[{{ $loop->index }}][name]" value="{{ $u->unit_name }}"
                                placeholder="Unit"
                                class="border rounded-lg px-3 py-2 w-1/2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                            <input type="number" step="0.0001" name="units[{{ $loop->index }}][conversion]"
                                value="{{ format_number($u->conversion_to_base) }}" placeholder="Konversi"
                                class="border rounded-lg px-3 py-2 w-1/2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-100 flex justify-between items-center">
            <button type="button" onclick="addUnitRow()"
                class="text-blue-600 text-sm font-bold tracking-tight hover:underline">+ Tambah Baris</button>
            <button type="submit"
                class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition shadow-md">
                Simpan Units
            </button>
        </div>
    </form>
</div>