{{-- Berat & dimensi untuk perhitungan ongkir (Fase 0 shipping). Posting ke updateInfo. --}}
<div class="bg-white rounded-lg shadow p-4 mb-4">
    <h3 class="font-semibold text-gray-700 mb-3">Berat &amp; Dimensi (Pengiriman)</h3>
    <form action="{{ route('inventory.products.update-info', $product->id) }}" method="POST"
          class="flex flex-wrap gap-3 items-end text-sm">
        @csrf
        <div>
            <label class="block text-xs text-gray-500 mb-1">Berat (gram) *</label>
            <input type="number" name="weight_gram" min="0" step="1" value="{{ old('weight_gram', $product->weight_gram) }}"
                   class="border rounded px-2 py-1.5 w-32" placeholder="0">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Panjang (cm)</label>
            <input type="number" name="length_cm" min="0" step="0.1" value="{{ old('length_cm', $product->length_cm) }}"
                   class="border rounded px-2 py-1.5 w-24" placeholder="—">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Lebar (cm)</label>
            <input type="number" name="width_cm" min="0" step="0.1" value="{{ old('width_cm', $product->width_cm) }}"
                   class="border rounded px-2 py-1.5 w-24" placeholder="—">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Tinggi (cm)</label>
            <input type="number" name="height_cm" min="0" step="0.1" value="{{ old('height_cm', $product->height_cm) }}"
                   class="border rounded px-2 py-1.5 w-24" placeholder="—">
        </div>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-1.5 rounded text-sm font-semibold">Simpan</button>
    </form>
    <p class="text-[11px] text-gray-400 mt-2">Berat wajib diisi agar produk bisa dihitung ongkirnya. Dimensi opsional (untuk volumetrik).</p>
</div>
