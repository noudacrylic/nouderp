{{-- Berat & dimensi untuk perhitungan ongkir (Fase 0 shipping). Posting ke updateInfo. --}}
<div class="bg-white shadow-lg rounded-xl border border-gray-100 mt-8 overflow-hidden">
    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">Berat &amp; Dimensi (Pengiriman)</h3>
    </div>
    <div class="p-6">
        <form action="{{ route('inventory.products.update-info', $product->id) }}" method="POST">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-3">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Berat (gram) <span class="text-red-500">*</span></label>
                    <input type="number" name="weight_gram" min="0" step="1" value="{{ old('weight_gram', $product->weight_gram) }}"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition" placeholder="0">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Panjang (cm)</label>
                    <input type="number" name="length_cm" min="0" step="0.1" value="{{ old('length_cm', $product->length_cm) }}"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition" placeholder="—">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Lebar (cm)</label>
                    <input type="number" name="width_cm" min="0" step="0.1" value="{{ old('width_cm', $product->width_cm) }}"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition" placeholder="—">
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold text-gray-400 uppercase mb-1">Tinggi (cm)</label>
                    <input type="number" name="height_cm" min="0" step="0.1" value="{{ old('height_cm', $product->height_cm) }}"
                        class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition" placeholder="—">
                </div>

                <div class="md:col-span-3">
                    <button type="submit"
                        class="w-full bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 active:transform active:scale-95 transition shadow-lg shadow-blue-100">
                        Simpan
                    </button>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-3">Berat wajib diisi agar produk bisa dihitung ongkirnya. Dimensi opsional (untuk volumetrik).</p>
        </form>
    </div>
</div>
