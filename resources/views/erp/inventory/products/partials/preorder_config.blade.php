<div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full">
    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">Konfigurasi Preorder</h3>
    </div>
    <form action="{{ route('inventory.products.preorder-config', $product->id) }}" method="POST" class="p-6 space-y-4">
        @csrf
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Jumlah Slot Preorder</label>
            <input type="number" name="preorder_stock" value="{{ $product->preorder_stock }}"
                class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition"
                required>
            <p class="text-[10px] text-gray-400 mt-1 italic">Total kuota item yang dapat dipesan.</p>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Lead Time (Hari)</label>
            <input type="number" name="lead_time_days" value="{{ $product->lead_time_days }}"
                class="w-full border rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 outline-none transition"
                required>
            <p class="text-[10px] text-gray-400 mt-1 italic">Perkiraan waktu pengerjaan/pengiriman (hari).</p>
        </div>

        <button type="submit"
            class="w-full bg-blue-600 text-white py-2 rounded-lg font-bold text-sm hover:bg-blue-700 shadow-md transition">Simpan
            Konfigurasi Preorder</button>
    </form>
</div>