@extends('layouts.erp')

@section('content')

    <div class="max-w-2xl mx-auto bg-white shadow rounded-lg">

        <div class="p-6 border-b">
            <h2 class="text-xl font-semibold">
                Create New Product
            </h2>

            <p class="text-sm text-gray-500">
                Langkah 1: Masukkan informasi dasar produk
            </p>
        </div>

        <form method="POST" action="{{ route('inventory.products.store') }}">

            @csrf

            <div class="p-6 space-y-5">

                @if ($errors->any())
                    <div class="bg-red-50 border-l-4 border-red-400 text-red-700 text-sm rounded p-3 mb-3">
                        @foreach ($errors->all() as $err)
                            <div>{{ $err }}</div>
                        @endforeach
                    </div>
                @endif

                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">
                        SKU / Kode Produk
                    </label>

                    <input type="text" id="sku" name="sku" value="{{ old('sku') }}" class="w-full border rounded px-3 py-2"
                        placeholder="Contoh: KS-K1-M3">
                </div>

                <div class="flex items-center gap-2 mb-4">
                    <input type="checkbox" id="auto_sku" name="auto_sku" value="1" {{ old('auto_sku') ? 'checked' : '' }}>
                    <label for="auto_sku" class="text-sm">Gunakan penomoran otomatis</label>
                </div>


                <div>
                    <label class="block text-sm font-medium mb-1">
                        Nama Produk
                    </label>

                    <input type="text" name="name" required value="{{ old('name') }}" class="w-full border rounded px-3 py-2"
                        placeholder="Masukkan nama produk">
                </div>


                <div>
                    <label class="block text-sm font-medium mb-1">
                        Tipe Produk
                    </label>

                    <select name="type" required class="w-full border rounded px-3 py-2">

                        <option value="">-- Pilih Tipe --</option>

                        @php $oldType = old('type'); @endphp
                        <option value="ready"     @selected($oldType === 'ready')>Ready Stock</option>
                        <option value="preorder"  @selected($oldType === 'preorder')>Preorder</option>
                        <option value="bundle"    @selected($oldType === 'bundle')>Bundle</option>
                        <option value="service"   @selected($oldType === 'service')>Jasa (Service)</option>
                        <option value="non_stock" @selected($oldType === 'non_stock')>Non Stock</option>

                    </select>

                </div>

            </div>

            <div class="p-6 border-t flex justify-end">

                <button class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">

                    Create & Continue Setup

                </button>

            </div>

        </form>

    </div>

@endsection

@section('scripts')
    <script>
        document.getElementById('auto_sku').addEventListener('change', function () {
            let sku = document.getElementById('sku');
            if (this.checked) {
                sku.value = '';
                sku.disabled = true;
            } else {
                sku.disabled = false;
            }
        });

        document.querySelector('[name="type"]').addEventListener('change', function () {
            let autoSku = document.getElementById('auto_sku');
            let sku = document.getElementById('sku');

            if (this.value === 'service' || this.value === 'non_stock') {
                // Service & non_stock wajib auto-SKU (tidak ada manual SKU di tipe ini).
                autoSku.checked = true;
                autoSku.disabled = true;
                sku.disabled = true;
                sku.value = '';
            } else {
                // Tipe lain: pertahankan pilihan user; hanya re-enable & sync state SKU input.
                autoSku.disabled = false;
                sku.disabled = autoSku.checked;
                if (autoSku.checked) sku.value = '';
            }
        });
    </script>
@endsection