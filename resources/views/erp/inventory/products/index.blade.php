@extends('layouts.erp')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-semibold">Produk</h1>
    <div class="flex gap-2">
        <a href="{{ route('inventory.products.export', request()->query()) }}" class="bg-slate-600 hover:bg-slate-700 text-white px-3 py-2 rounded text-sm" title="Download produk sesuai filter aktif">📥 Download Excel</a>
        <a href="{{ route('inventory.products.bulk-import') }}" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded text-sm">📤 Upload Excel</a>
        <a href="{{ route('inventory.products.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm">+ Tambah Produk</a>
    </div>
</div>

<form method="GET" class="bg-white rounded shadow p-3 mb-3 flex gap-3 items-end text-sm flex-wrap">
    @include('erp.purchasing._partials.search-input', ['name' => 'search', 'placeholder' => 'Cari SKU / nama produk...'])

    <div>
        <label class="block text-xs text-gray-500 mb-1">Tipe</label>
        <select name="type" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="ready" @selected(request('type') == 'ready')>Ready Stock</option>
            <option value="preorder" @selected(request('type') == 'preorder')>Preorder</option>
            <option value="service" @selected(request('type') == 'service')>Service</option>
            <option value="non_stock" @selected(request('type') == 'non_stock')>Non Stock</option>
            <option value="bundle" @selected(request('type') == 'bundle')>Bundle</option>
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="filter-auto border rounded px-2 py-1.5">
            <option value="active" @selected((request('status') ?? 'active') == 'active')>Aktif</option>
            <option value="archived" @selected(request('status') == 'archived')>Arsip</option>
            <option value="all" @selected(request('status') == 'all')>Semua</option>
        </select>
    </div>

    <div>
        <label class="block text-xs text-gray-500 mb-1">Dijual</label>
        <select name="sellable" class="filter-auto border rounded px-2 py-1.5">
            <option value="">Semua</option>
            <option value="yes" @selected(request('sellable') == 'yes')>Dijual</option>
            <option value="no" @selected(request('sellable') == 'no')>Tidak Dijual</option>
        </select>
    </div>
</form>

{{-- Toggle "Dijual": CSS eksplisit (tidak bergantung Tailwind JIT/peer-checked yang tak tergenerate via CDN). --}}
<style>
    .sellable-toggle { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .sellable-switch {
        position: relative; display: inline-block; flex: 0 0 auto;
        width: 36px; height: 20px; border-radius: 9999px;
        background: #d1d5db; transition: background .15s ease;
    }
    .sellable-switch::after {
        content: ''; position: absolute; top: 2px; left: 2px;
        width: 16px; height: 16px; border-radius: 9999px;
        background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.25);
        transition: transform .15s ease;
    }
    .sellable-toggle:checked + .sellable-switch { background: #22c55e; }
    .sellable-toggle:checked + .sellable-switch::after { transform: translateX(16px); }
    .sellable-toggle:focus-visible + .sellable-switch { box-shadow: 0 0 0 3px rgba(34,197,94,.35); }
</style>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Produk</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-center">Dijual</th>
                <th class="px-3 py-2 text-left">Unit</th>
                <th class="px-3 py-2 text-right">Harga Dasar</th>
                <th class="px-3 py-2 text-center w-40">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($products as $product)
                <tr class="border-b hover:bg-blue-50 cursor-pointer" data-href="{{ route('inventory.products.setup', $product->id) }}">
                    <td class="px-3 py-2">
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <span class="text-xs font-mono text-gray-500 whitespace-nowrap">
                                {{ $product->sku }}
                                @include('erp.purchasing._partials.copy-btn', ['value' => $product->sku])
                            </span>
                            <span class="font-medium text-gray-900">{{ $product->name }}</span>
                        </div>
                    </td>
                    <td class="px-3 py-2">
                        <span class="px-2 py-0.5 rounded text-xs uppercase bg-blue-50 text-blue-700">
                            {{ str_replace('_', ' ', $product->sale_type) }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-center whitespace-nowrap">
                        @if ($product->is_active)
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-green-100 text-green-700">Aktif</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-500">Arsip</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <label class="inline-flex items-center gap-2 cursor-pointer select-none" title="Klik untuk ubah: muncul di Kasir/POS/Penawaran/SO/Faktur/Promosi atau tidak">
                            <input type="checkbox" class="sellable-toggle" data-product-id="{{ $product->id }}" @checked($product->is_sellable)>
                            <span class="sellable-switch"></span>
                            <span class="sellable-label text-xs font-semibold {{ $product->is_sellable ? 'text-green-600' : 'text-gray-400' }}">{{ $product->is_sellable ? 'Dijual' : 'Tidak' }}</span>
                        </label>
                    </td>
                    <td class="px-3 py-2 text-gray-600">{{ $product->base_unit ?? '-' }}</td>
                    <td class="px-3 py-2 text-right" onclick="event.stopPropagation()">
                        <span class="text-gray-500 text-xs">Rp</span>
                        <input type="text" value="{{ number_format($product->display_price, 0, ',', '.') }}"
                               data-product-id="{{ $product->id }}"
                               class="price-inline rupiah-input w-24 border rounded px-2 py-1 text-right font-medium text-gray-900 focus:ring-2 focus:ring-blue-500">
                    </td>
                    <td class="px-3 py-2 text-center" onclick="event.stopPropagation()">
                        <div class="flex gap-1 justify-center flex-wrap">
                            @if (!$product->is_active)
                                <form method="POST" action="{{ route('inventory.products.restore', $product->id) }}">
                                    @csrf
                                    <button class="bg-green-600 text-white px-2 py-1 rounded text-xs">Restore</button>
                                </form>
                            @elseif ($product->is_used)
                                <form method="POST" action="{{ route('inventory.products.archive', $product->id) }}"
                                      onsubmit="return confirm('Archive produk ini?')">
                                    @csrf
                                    <button class="bg-yellow-500 text-white px-2 py-1 rounded text-xs">Archive</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('inventory.products.destroy', $product->id) }}"
                                      onsubmit="return confirm('Hapus produk ini secara permanen?')">
                                    @csrf @method('DELETE')
                                    <button class="bg-red-600 text-white px-2 py-1 rounded text-xs">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada produk.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($products instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $products->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')

@push('scripts')
<script>
    function formatRupiah(angka) {
        let number_string = angka.replace(/[^,\d]/g, '').toString();
        let split = number_string.split(',');
        let sisa = split[0].length % 3;
        let rupiah = split[0].substr(0, sisa);
        let ribuan = split[0].substr(sisa).match(/\d{3}/gi);
        if (ribuan) {
            let separator = sisa ? '.' : '';
            rupiah += separator + ribuan.join('.');
        }
        return rupiah;
    }

    document.querySelectorAll('.rupiah-input').forEach(function (el) {
        el.addEventListener('keyup', function () {
            this.value = formatRupiah(this.value);
        });
    });

    document.querySelectorAll('.price-inline').forEach(function (el) {
        el.addEventListener('change', function () {
            let price = this.value.replace(/\./g, '');
            fetch('/erp/inventory/products/update-price', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    product_id: this.dataset.productId,
                    price: price
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        el.classList.add('border-green-400', 'bg-green-50');
                        setTimeout(() => el.classList.remove('border-green-400', 'bg-green-50'), 800);
                    }
                });
        });
    });

    // Toggle "Dijual" (is_sellable) langsung dari index — tanpa buka edit.
    document.querySelectorAll('.sellable-toggle').forEach(function (el) {
        el.addEventListener('change', function () {
            const on = this.checked;
            const label = this.closest('label')?.querySelector('.sellable-label');
            this.disabled = true;
            fetch('/erp/inventory/products/update-sellable', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({ product_id: this.dataset.productId, is_sellable: on ? 1 : 0 })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (label) {
                            label.textContent = data.is_sellable ? 'Dijual' : 'Tidak';
                            label.classList.toggle('text-green-600', data.is_sellable);
                            label.classList.toggle('text-gray-400', !data.is_sellable);
                        }
                    } else {
                        this.checked = !on; // gagal → kembalikan
                    }
                })
                .catch(() => { this.checked = !on; })
                .finally(() => { this.disabled = false; });
        });
    });
</script>
@endpush
@endsection
