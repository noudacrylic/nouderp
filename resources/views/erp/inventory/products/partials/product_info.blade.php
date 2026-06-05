<div class="bg-white shadow rounded-lg p-6 border border-gray-100">
    <h2 class="text-xs font-bold text-gray-400 uppercase tracking-widest mb-4">Informasi Produk</h2>
    <form action="{{ route('inventory.products.update-info', $product->id) }}" method="POST">
        @csrf
        <input type="hidden" name="jubelio_section" value="1">
        <input type="hidden" name="sellable_section" value="1">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">SKU</label>
                <input type="text" name="sku" value="{{ $product->sku }}"
                    class="w-full border rounded-lg px-4 py-2 bg-gray-50 focus:bg-white transition {{ $product->is_used ? 'opacity-50 cursor-not-allowed' : '' }}"
                    {{ $product->is_used ? 'readonly' : '' }} required>
                @if ($product->is_used)
                    <p class="text-[10px] text-red-500 mt-1 font-medium italic">* SKU tidak dapat diubah karena sudah
                        ada transaksi.</p>
                @endif
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Nama Produk</label>
                <input type="text" name="name" value="{{ $product->name }}"
                    class="w-full border rounded-lg px-4 py-2 bg-gray-50 focus:bg-white transition" required>
            </div>
        </div>
        <div class="mt-4 border-t pt-4">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="is_sellable" value="1" {{ ($product->is_sellable ?? true) ? 'checked' : '' }}
                    class="rounded border-gray-300">
                Dijual (tampil di Kasir, Penawaran &amp; Faktur)
            </label>
            <p class="text-[11px] text-gray-400 mt-1">Centang bila produk dijual langsung ke pembeli. Matikan untuk produk setengah jadi, komponen wajib, atau pelengkap bundle — tetap bisa diproduksi &amp; dipakai sbg komponen, tapi tidak muncul saat memilih barang jualan.</p>
        </div>
        <div class="mt-4 border-t pt-4">
            <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                <input type="checkbox" name="sync_to_jubelio" value="1" {{ $product->sync_to_jubelio ? 'checked' : '' }}
                    class="rounded border-gray-300">
                Sinkron stok &amp; harga ke Jubelio
            </label>
            <p class="text-[11px] text-gray-400 mt-1">Aktifkan untuk produk yang dijual di marketplace. Biarkan mati untuk bahan baku, komponen, atau produk sampingan.</p>
        </div>
        <div class="mt-4 flex justify-end">
            <button type="submit"
                class="bg-gray-800 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-gray-900 transition">Perbarui
                Info Produk</button>
        </div>
    </form>
</div>