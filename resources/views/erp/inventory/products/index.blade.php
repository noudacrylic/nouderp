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
            <option value="active" @selected((request('status') ?? 'active') == 'active')>Active</option>
            <option value="archived" @selected(request('status') == 'archived')>Archived</option>
            <option value="all" @selected(request('status') == 'all')>All</option>
        </select>
    </div>
</form>

<div class="bg-white rounded shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b text-gray-600">
            <tr>
                <th class="px-3 py-2 text-left">Produk</th>
                <th class="px-3 py-2 text-left">Tipe</th>
                <th class="px-3 py-2 text-center">Status</th>
                <th class="px-3 py-2 text-left">Unit</th>
                <th class="px-3 py-2 text-right">Base Price</th>
                <th class="px-3 py-2 text-center w-40">Action</th>
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
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-green-100 text-green-700">Active</span>
                        @else
                            <span class="px-2 py-0.5 rounded text-xs uppercase bg-gray-100 text-gray-500">Archived</span>
                        @endif
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
                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada produk.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($products instanceof \Illuminate\Contracts\Pagination\Paginator)
    <div class="mt-3">{{ $products->links() }}</div>
@endif

@include('erp.purchasing._partials.list-scripts')

@push('scripts_extra')
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
</script>
@endpush
@endsection
