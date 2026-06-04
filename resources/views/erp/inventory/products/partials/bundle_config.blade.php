<div class="bg-white shadow rounded-lg border border-gray-100 flex flex-col h-full">

    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
        <h3 class="font-bold text-gray-700 uppercase tracking-tight text-sm">KOMPONEN BUNDLE</h3>
    </div>

    <form method="POST" action="{{ route('inventory.products.bundle.save', $product->id) }}"
        class="p-6 flex-1 flex flex-col">
        @csrf

        <div id="bundle-wrapper" class="space-y-3 flex-1">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-2">DAFTAR KOMPONEN</label>

            @foreach($product->bundleComponents as $c)
                <div class="flex gap-2 bundle-row animate-fadeIn items-center">
                    <div class="w-2/3">
                        <select name="components[{{ $loop->index }}][product_id]"
                            class="product-search border rounded-lg px-3 py-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                            <option value="">-- Pilih Produk --</option>
                            @foreach($allProducts as $p)
                                @if($p->id != $product->id)
                                    <option value="{{ $p->id }}" @if($p->id == $c->component_product_id) selected @endif>
                                        {{ $p->sku }} - {{ $p->name }}
                                    </option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <input type="number" step="0.0001" name="components[{{ $loop->index }}][qty]"
                        value="{{ format_number($c->qty) }}" placeholder="Qty"
                        class="border rounded-lg px-3 py-2 w-1/3 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">

                    <button type="button" onclick="removeBundleRow(this)"
                        class="text-red-500 text-xs font-bold hover:underline">Hapus</button>
                </div>
            @endforeach
        </div>

        <div class="flex justify-between items-center mt-4 pt-4 border-t border-gray-100">
            <button type="button" onclick="addBundleRow()"
                class="text-blue-600 text-sm font-bold tracking-tight hover:underline">+ Tambah Komponen</button>
            <button type="submit"
                class="bg-blue-600 text-white px-6 py-2 rounded-lg font-bold text-sm hover:bg-blue-700 transition shadow-md">
                Simpan Komponen
            </button>
        </div>
    </form>
</div>

@push('scripts_extra')
    <script>
        function addBundleRow() {
            const wrapper = document.getElementById('bundle-wrapper');
            const index = wrapper.querySelectorAll('.bundle-row').length;

            const row = document.createElement('div');
            row.className = "flex gap-2 bundle-row animate-fadeIn items-center";

            row.innerHTML = `
                                <div class="w-2/3">
                                    <select name="components[${index}][product_id]" class="product-search border rounded-lg px-3 py-2 w-full text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">
                                        <option value="">-- Pilih Produk --</option>
                                        @foreach($allProducts as $p)
                                            @if($p->id != $product->id)
                                                <option value="{{ $p->id }}">
                                                    {{ $p->sku }} - {{ $p->name }}
                                                </option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>

                                <input type="number" step="0.0001" name="components[${index}][qty]" value="1" placeholder="Qty"
                                    class="border rounded-lg px-3 py-2 w-1/3 text-sm focus:ring-2 focus:ring-blue-500 outline-none transition">

                                <button type="button" onclick="removeBundleRow(this)" class="text-red-500 text-xs font-bold hover:underline">Hapus</button>
                            `;

            wrapper.appendChild(row);

            // Inisialisasi Select2 KHUSUS pada elemen yang baru ditambahkan
            $(row).find('.product-search').select2({
                placeholder: "Cari produk...",
                width: '100%'
            });
        }

        function removeBundleRow(btn) {
            btn.closest('.bundle-row').remove();
        }
    </script>
@endpush